<?php

namespace App\Alerting\Engine;

use App\Alerting\Engine\Signals\ChangeSignal;
use App\Alerting\Engine\Signals\StatusSignal;
use App\Enums\MonitorStatus;
use App\Events\MonitorChanged;
use App\Events\MonitorStatusChanged;
use App\Jobs\EvaluateAlerts;
use App\Monitoring\Engine\PostCommit;
use App\Monitoring\Engine\SafeText;
use Carbon\CarbonImmutable;

/**
 * Turns the events of a recorded batch into alert signals, and signals into a queue payload of scalars and back.
 *
 * Signal times are cut to whole seconds, the precision alert_states and alert_events store, so a replayed
 * signal compares equal to the one already applied.
 */
final class AlertSignals
{
    /**
     * Monitors whose alert signals go into one EvaluateAlerts job.
     */
    public const CHUNK = 200;

    /**
     * Queues the alert evaluation of the status and value changes among the events. Call it inside the
     * transaction that made the changes. On a database queue that uses this connection the jobs are written in
     * that transaction, so a committed change always has its evaluation queued and a rolled-back one never has;
     * nothing is returned then. For any other queue the jobs are returned, and the caller passes them to
     * PostCommit::queue() once the transaction has committed, which loses them if the process dies in between.
     *
     * @param  list<object>  $events
     * @return list<EvaluateAlerts> The jobs to queue after commit.
     */
    public static function queue(array $events): array
    {
        $signals = self::fromEvents($events);
        if ($signals === []) {
            return [];
        }

        $atomic = PostCommit::queueJoinsTransaction();
        $jobs = [];
        foreach (array_chunk($signals, self::CHUNK, true) as $chunk) {
            $job = new EvaluateAlerts(self::toPayload($chunk));
            if ($atomic) {
                dispatch($job);
            } else {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    /**
     * Signals of one monitor are ordered by time; at the same time a status change comes before a value change,
     * because applying a value change moves the machine's clock to that time.
     *
     * @param  list<object>  $events
     * @return array<int, list<StatusSignal|ChangeSignal>> Keyed by monitor id.
     */
    public static function fromEvents(array $events): array
    {
        /** @var array<int, list<StatusSignal>> $status */
        $status = [];
        /** @var array<int, array<int, list<array{key: string, old: ?string, new: string}>>> $changes */
        $changes = [];

        foreach ($events as $event) {
            if ($event instanceof MonitorStatusChanged) {
                $status[$event->monitorId][] = new StatusSignal(
                    $event->from, $event->to, $event->at->startOfSecond(), $event->previousSince->startOfSecond(), $event->message,
                );
            } elseif ($event instanceof MonitorChanged) {
                $changes[$event->monitorId][$event->at->getTimestamp()][] = ['key' => $event->key, 'old' => $event->old, 'new' => $event->new];
            }
        }

        $byMonitor = [];
        foreach (array_unique([...array_keys($status), ...array_keys($changes)]) as $monitorId) {
            $signals = $status[$monitorId] ?? [];
            foreach ($changes[$monitorId] ?? [] as $timestamp => $list) {
                $signals[] = new ChangeSignal(CarbonImmutable::createFromTimestampUTC($timestamp), $list);
            }
            $byMonitor[$monitorId] = self::sorted($signals);
        }
        ksort($byMonitor);

        return $byMonitor;
    }

    /**
     * Text is cleaned first: a value that cannot be JSON-encoded would fail the queue insert, and with it the
     * transaction of the recorded batch. Watched values stored before they were cleaned can still hold such text.
     *
     * @param  array<int, list<StatusSignal|ChangeSignal>>  $byMonitor
     * @return list<array<string, mixed>>
     */
    public static function toPayload(array $byMonitor): array
    {
        $payload = [];
        foreach ($byMonitor as $monitorId => $signals) {
            foreach ($signals as $signal) {
                $payload[] = $signal instanceof StatusSignal
                    ? [
                        'monitor_id' => $monitorId,
                        'kind' => 'status',
                        'from' => $signal->from->value,
                        'to' => $signal->to->value,
                        'at' => $signal->at->getTimestamp(),
                        'previous_since' => $signal->previousSince->getTimestamp(),
                        'message' => SafeText::clean($signal->message),
                    ]
                    : [
                        'monitor_id' => $monitorId,
                        'kind' => 'change',
                        'at' => $signal->at->getTimestamp(),
                        'changes' => array_map(static fn (array $change): array => [
                            'key' => SafeText::clean($change['key']),
                            'old' => $change['old'] === null ? null : SafeText::clean($change['old']),
                            'new' => SafeText::clean($change['new']),
                        ], $signal->changes),
                    ];
            }
        }

        return $payload;
    }

    /**
     * Entries that do not decode are skipped.
     *
     * @param  array<mixed>  $payload
     * @return array<int, list<StatusSignal|ChangeSignal>>
     */
    public static function fromPayload(array $payload): array
    {
        $byMonitor = [];
        foreach ($payload as $entry) {
            if (! is_array($entry) || ! is_int($entry['monitor_id'] ?? null) || ! is_int($entry['at'] ?? null)) {
                continue;
            }
            $at = CarbonImmutable::createFromTimestampUTC($entry['at']);

            if (($entry['kind'] ?? null) === 'status') {
                $from = MonitorStatus::tryFrom(is_string($entry['from'] ?? null) ? $entry['from'] : '');
                $to = MonitorStatus::tryFrom(is_string($entry['to'] ?? null) ? $entry['to'] : '');
                if ($from === null || $to === null) {
                    continue;
                }
                $since = is_int($entry['previous_since'] ?? null) ? CarbonImmutable::createFromTimestampUTC($entry['previous_since']) : $at;
                $message = is_string($entry['message'] ?? null) ? $entry['message'] : '';
                $byMonitor[$entry['monitor_id']][] = new StatusSignal($from, $to, $at, $since, $message);
            } elseif (($entry['kind'] ?? null) === 'change' && is_array($entry['changes'] ?? null)) {
                $changes = [];
                foreach ($entry['changes'] as $change) {
                    if (is_array($change) && is_string($change['key'] ?? null) && is_string($change['new'] ?? null)) {
                        $old = $change['old'] ?? null;
                        $changes[] = ['key' => $change['key'], 'old' => is_string($old) ? $old : null, 'new' => $change['new']];
                    }
                }
                if ($changes !== []) {
                    $byMonitor[$entry['monitor_id']][] = new ChangeSignal($at, $changes);
                }
            }
        }

        foreach ($byMonitor as $monitorId => $signals) {
            $byMonitor[$monitorId] = self::sorted($signals);
        }
        ksort($byMonitor);

        return $byMonitor;
    }

    /**
     * @param  list<StatusSignal|ChangeSignal>  $signals
     * @return list<StatusSignal|ChangeSignal>
     */
    private static function sorted(array $signals): array
    {
        // usort is stable, so signals of the same time and kind keep the order they were recorded in.
        usort($signals, static fn (StatusSignal|ChangeSignal $a, StatusSignal|ChangeSignal $b): int => [$a->at->getTimestamp(), $a instanceof ChangeSignal ? 1 : 0]
            <=> [$b->at->getTimestamp(), $b instanceof ChangeSignal ? 1 : 0]);

        return $signals;
    }
}
