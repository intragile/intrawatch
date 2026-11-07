<?php

namespace App\Alerting\Engine;

use App\Alerting\Engine\Signals\ChangeSignal;
use App\Alerting\Engine\Signals\StatusSignal;
use App\Alerting\Engine\Signals\TickSignal;
use App\Enums\AlertKind;
use App\Enums\ChannelType;
use App\Enums\DeliveryStatus;
use App\Enums\MonitorStatus;
use App\Events\AlertRaised;
use App\Jobs\SendNotification;
use App\Monitoring\Engine\PostCommit;
use App\Monitoring\Engine\SafeText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use JsonException;
use stdClass;

/**
 * Applies alert signals to the monitors' alert states, records the alerts and queues their deliveries.
 *
 * Everything happens in one transaction: states, alert events, deliveries and the SendNotification jobs (on the
 * database queue, the jobs table is written in the same transaction). Lock order: monitor rows (shared), then
 * alert_states rows (exclusive), each in monitor id order.
 *
 * Replays are no-ops. A status signal older than the last one applied is dropped by the machine, and one with
 * the same time changes nothing because its class is already observed. A value change signal re-emits its
 * alerts, so it is skipped when alerts of a change kind already exist for that monitor and time; change alerts
 * are stored with the signal's time for that reason.
 */
final class AlertEvaluator
{
    private const CHUNK = 500;

    /** Alert events per digest delivery. */
    private const DIGEST_CHUNK = 200;

    /** Seconds a due time is moved on by when the policy gives no positive re-check interval. */
    private const FALLBACK_RECHECK_SECONDS = 60;

    private const CHANGE_KINDS = [AlertKind::Expiring, AlertKind::Renewed, AlertKind::Changed];

    private const STATE_COLUMNS = [
        'notified', 'observed', 'current', 'problem_since', 'down_since', 'degraded_since', 'flapping', 'flapping_since',
        'recent_changes', 'last_notified_at', 'reminders_sent', 'suppressed', 'last_signal_at', 'due_at', 'updated_at',
    ];

    public function __construct(
        private readonly PolicyLoader $policies,
        private readonly SuppressionMap $suppression,
    ) {}

    /**
     * Signals of paused and deleted monitors are dropped; a paused monitor keeps its state, but a tick clears its
     * due time so it is not selected again until a new signal arrives.
     *
     * @param  array<int, list<StatusSignal|ChangeSignal|TickSignal>>  $signalsByMonitor  Ordered per monitor.
     * @return int The number of alert events recorded.
     */
    public function evaluate(array $signalsByMonitor, CarbonImmutable $now): int
    {
        $signalsByMonitor = array_filter($signalsByMonitor, static fn (array $signals): bool => $signals !== []);
        if ($signalsByMonitor === []) {
            return 0;
        }
        ksort($signalsByMonitor);

        /** @var array<int, list<int>> $raised */
        $raised = [];
        /** @var list<SendNotification> $sends */
        $sends = [];
        $count = DB::transaction(function () use ($signalsByMonitor, $now, &$raised, &$sends): int {
            $raised = [];
            $sends = [];

            return $this->run($signalsByMonitor, $now, $raised, $sends);
        });

        $events = [];
        foreach ($raised as $workspaceId => $ids) {
            $events[] = new AlertRaised($workspaceId, $ids);
        }
        PostCommit::dispatch($events);
        PostCommit::queue($sends);

        return $count;
    }

    /**
     * @param  array<int, list<StatusSignal|ChangeSignal|TickSignal>>  $signalsByMonitor
     * @param  array<int, list<int>>  $raised  Receives the recorded alert event ids by workspace.
     * @param  list<SendNotification>  $sends  Receives the sends to queue after commit.
     */
    private function run(array $signalsByMonitor, CarbonImmutable $now, array &$raised, array &$sends): int
    {
        $policies = $this->policies->load(array_keys($signalsByMonitor));

        $active = [];
        $pausedTicks = [];
        foreach ($policies as $id => $policy) {
            if ($policy->active) {
                $active[] = $id;
            } elseif ($signalsByMonitor[$id][0] instanceof TickSignal) {
                $pausedTicks[] = $id;
            }
        }
        if ($active === [] && $pausedTicks === []) {
            return 0;
        }

        $states = $this->lockStates($active, $pausedTicks);
        $suppressedBy = $this->suppression->for($active);
        $replayed = $this->replayedChanges($signalsByMonitor, $active);

        $stateRows = [];
        $eventRows = [];
        foreach ($pausedTicks as $id) {
            if (isset($states[$id]) && $states[$id]->dueAt !== null) {
                $stateRows[] = $this->stateRow($id, self::withDueAt($states[$id], null), $now);
            }
        }

        foreach ($active as $id) {
            $policy = $policies[$id];
            $state = $states[$id] ?? AlertSnapshot::initial();
            $before = $state;
            $suppressor = $suppressedBy[$id] ?? null;
            $ticked = false;

            foreach ($signalsByMonitor[$id] as $signal) {
                if ($signal instanceof TickSignal && ($state->dueAt === null || $state->dueAt->greaterThan($signal->now))) {
                    // Another evaluation moved the due time on after this monitor was selected.
                    continue;
                }
                $ticked = $ticked || $signal instanceof TickSignal;
                if ($signal instanceof ChangeSignal && isset($replayed[$id][$signal->at->getTimestamp()])) {
                    continue;
                }

                $decision = AlertMachine::apply($policy->policy, $state, $signal, $suppressor !== null);
                $state = $decision->state;
                $at = $signal instanceof TickSignal ? $now : $signal->at;
                foreach ($decision->alerts as $alert) {
                    $eventRows[] = $this->eventRow($policy, $alert, $alert->suppressed ? $suppressor : null, $at);
                }
            }

            if ($ticked && $state->dueAt !== null && $state->dueAt->lessThanOrEqualTo($now)) {
                // A due tick must move the due time past now, or the tick selects this state again on every page
                // and every run. The machine always does; this guards against a policy (or a defect) that does not.
                $recheck = $policy->policy->suppressionRecheckSeconds;
                $state = self::withDueAt($state, $now->addSeconds($recheck > 0 ? $recheck : self::FALLBACK_RECHECK_SECONDS));
                Log::warning('An alert tick left a due time that is not in the future; it was moved on.', ['monitor_id' => $id]);
            }

            if ($state != $before || ! isset($states[$id])) {
                $stateRows[] = $this->stateRow($id, $state, $now);
            }
        }

        foreach (array_chunk($stateRows, self::CHUNK) as $chunk) {
            DB::table('alert_states')->upsert($chunk, ['monitor_id'], self::STATE_COLUMNS);
        }

        if ($eventRows === []) {
            return 0;
        }

        $events = $this->insertEvents($eventRows);
        foreach ($events as $event) {
            $raised[$event['workspace_id']][] = $event['id'];
        }

        $this->queueDeliveries($events, $now, $sends);

        return count($events);
    }

    /**
     * Creates the missing state rows of active monitors and locks all rows in monitor id order.
     *
     * Existing rows are found with a plain read first: an insert that skips an existing row would take a shared
     * lock on it, and two evaluations upgrading their shared locks to exclusive ones deadlock.
     *
     * @param  list<int>  $active
     * @param  list<int>  $pausedTicks
     * @return array<int, AlertSnapshot>
     */
    private function lockStates(array $active, array $pausedTicks): array
    {
        $ids = [...$active, ...$pausedTicks];
        sort($ids);

        $existing = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach (DB::table('alert_states')->whereIn('monitor_id', $chunk)->pluck('monitor_id') as $id) {
                $existing[self::int($id)] = true;
            }
        }

        $missing = array_values(array_filter($active, static fn (int $id): bool => ! isset($existing[$id])));
        foreach (array_chunk($missing, self::CHUNK) as $chunk) {
            DB::table('alert_states')->insertOrIgnore(array_map(static fn (int $id): array => ['monitor_id' => $id], $chunk));
        }

        $states = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = DB::table('alert_states')->whereIn('monitor_id', $chunk)->orderBy('monitor_id')->lockForUpdate()->get();
            foreach ($rows as $row) {
                $states[self::int($row->monitor_id)] = $this->snapshot($row);
            }
        }

        return $states;
    }

    /**
     * The value change signals that already produced alerts, by monitor and time.
     *
     * @param  array<int, list<StatusSignal|ChangeSignal|TickSignal>>  $signalsByMonitor
     * @param  list<int>  $active
     * @return array<int, array<int, true>>
     */
    private function replayedChanges(array $signalsByMonitor, array $active): array
    {
        $monitors = [];
        $min = null;
        $max = null;
        foreach ($active as $id) {
            foreach ($signalsByMonitor[$id] as $signal) {
                if ($signal instanceof ChangeSignal) {
                    $monitors[$id] = true;
                    $min = $min === null || $signal->at->lessThan($min) ? $signal->at : $min;
                    $max = $max === null || $signal->at->greaterThan($max) ? $signal->at : $max;
                }
            }
        }
        if ($monitors === [] || $min === null || $max === null) {
            return [];
        }

        $replayed = [];
        foreach (array_chunk(array_keys($monitors), self::CHUNK) as $chunk) {
            $rows = DB::table('alert_events')
                ->whereIn('monitor_id', $chunk)
                ->whereIn('kind', array_map(static fn (AlertKind $kind): string => $kind->value, self::CHANGE_KINDS))
                ->whereBetween('created_at', [$min, $max])
                ->get(['monitor_id', 'created_at']);
            foreach ($rows as $row) {
                $replayed[self::int($row->monitor_id)][self::time($row->created_at)?->getTimestamp() ?? 0] = true;
            }
        }

        return $replayed;
    }

    /**
     * Inserts the alert events and reads back their ids. No other evaluation can add events for these monitors
     * meanwhile, since their state rows are locked, so the rows above the previous maximum id are exactly ours.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{id: int, workspace_id: int, monitor_id: int, suppressed: bool}>
     */
    private function insertEvents(array $rows): array
    {
        $before = self::int(DB::table('alert_events')->max('id'));
        $monitorIds = array_values(array_unique(array_map(static fn (array $row): int => self::int($row['monitor_id']), $rows)));

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('alert_events')->insert($chunk);
        }

        $events = [];
        foreach (array_chunk($monitorIds, self::CHUNK) as $chunk) {
            $inserted = DB::table('alert_events')
                ->whereIn('monitor_id', $chunk)
                ->where('id', '>', $before)
                ->get(['id', 'workspace_id', 'monitor_id', 'suppressed_by_monitor_id']);
            foreach ($inserted as $row) {
                $events[] = [
                    'id' => self::int($row->id),
                    'workspace_id' => self::int($row->workspace_id),
                    'monitor_id' => self::int($row->monitor_id),
                    'suppressed' => $row->suppressed_by_monitor_id !== null,
                ];
            }
        }
        usort($events, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $events;
    }

    /**
     * Routes the alerts that are not suppressed to the active channels bound to their monitors. A channel that
     * supports digests and receives more than the digest threshold gets one delivery per 200 alerts; otherwise
     * every alert is a delivery of its own.
     *
     * The sends are queued in this transaction when the queue writes to this database; otherwise they are handed
     * back to be queued after commit, so a rolled-back evaluation never sends anything.
     *
     * @param  list<array{id: int, workspace_id: int, monitor_id: int, suppressed: bool}>  $events
     * @param  list<SendNotification>  $sends  Receives the sends to queue after commit.
     */
    private function queueDeliveries(array $events, CarbonImmutable $now, array &$sends): void
    {
        $byMonitor = [];
        foreach ($events as $event) {
            if (! $event['suppressed']) {
                $byMonitor[$event['monitor_id']][] = $event['id'];
            }
        }
        if ($byMonitor === []) {
            return;
        }

        /** @var array<int, array{workspace_id: int, type: ?ChannelType, events: array<int, true>}> $channels */
        $channels = [];
        foreach (array_chunk(array_keys($byMonitor), self::CHUNK) as $chunk) {
            $rows = DB::table('monitor_notification')
                ->join('notification_channels', 'notification_channels.id', '=', 'monitor_notification.notification_channel_id')
                ->whereIn('monitor_notification.monitor_id', $chunk)
                ->where('notification_channels.active', true)
                ->get(['monitor_notification.monitor_id', 'notification_channels.id', 'notification_channels.workspace_id', 'notification_channels.type']);
            foreach ($rows as $row) {
                $channelId = self::int($row->id);
                $channels[$channelId] ??= [
                    'workspace_id' => self::int($row->workspace_id),
                    'type' => ChannelType::tryFrom(is_string($row->type) ? $row->type : ''),
                    'events' => [],
                ];
                foreach ($byMonitor[self::int($row->monitor_id)] ?? [] as $eventId) {
                    $channels[$channelId]['events'][$eventId] = true;
                }
            }
        }
        if ($channels === []) {
            return;
        }
        ksort($channels);

        $threshold = is_numeric(config('alerting.digest.threshold')) ? (int) config('alerting.digest.threshold') : 5;
        $rows = [];
        foreach ($channels as $channelId => $channel) {
            $eventIds = array_keys($channel['events']);
            sort($eventIds);
            $digest = $channel['type']?->definition()->supportsDigest() === true && count($eventIds) > $threshold;
            foreach ($digest ? array_chunk($eventIds, self::DIGEST_CHUNK) : array_map(static fn (int $id): array => [$id], $eventIds) as $ids) {
                $rows[] = [
                    'workspace_id' => $channel['workspace_id'],
                    'notification_channel_id' => $channelId,
                    'alert_event_ids' => self::json($ids),
                    'status' => DeliveryStatus::Queued->value,
                    'attempts' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $ours = array_fill_keys(array_column($events, 'id'), true);
        $before = self::int(DB::table('alert_deliveries')->max('id'));
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('alert_deliveries')->insert($chunk);
        }

        // Deliveries of a concurrent evaluation may be committed and visible by now (read committed); they name
        // alert events of other monitors, so the event ids tell ours apart.
        $deliveryIds = [];
        foreach (array_chunk(array_keys($channels), self::CHUNK) as $chunk) {
            $inserted = DB::table('alert_deliveries')
                ->whereIn('notification_channel_id', $chunk)
                ->where('id', '>', $before)
                ->get(['id', 'alert_event_ids']);
            foreach ($inserted as $row) {
                $ids = json_decode(is_string($row->alert_event_ids) ? $row->alert_event_ids : '[]', true);
                if (is_array($ids) && isset($ids[0]) && is_int($ids[0]) && isset($ours[$ids[0]])) {
                    $deliveryIds[] = self::int($row->id);
                }
            }
        }
        sort($deliveryIds);

        $jobs = array_map(static fn (int $id): SendNotification => new SendNotification($id), $deliveryIds);
        if (! PostCommit::queueJoinsTransaction()) {
            array_push($sends, ...$jobs);

            return;
        }
        foreach (array_chunk($jobs, self::CHUNK) as $chunk) {
            Queue::connection()->bulk($chunk, '', 'notifications');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function eventRow(LoadedPolicy $policy, PlannedAlert $alert, ?int $suppressedBy, CarbonImmutable $at): array
    {
        $changes = array_map(static fn (array $change): array => [
            'key' => $change['key'],
            'old' => $change['old'] === null ? null : SafeText::clean($change['old']),
            'new' => SafeText::clean($change['new']),
        ], $alert->changes);

        return [
            'workspace_id' => $policy->workspaceId,
            'monitor_id' => $policy->monitorId,
            'kind' => $alert->kind->value,
            'status' => $alert->status?->value,
            'previous_status' => $alert->previousStatus?->value,
            'message' => mb_substr(SafeText::clean($alert->message), 0, 500),
            'duration_seconds' => $alert->durationSeconds,
            'changes' => $changes === [] ? null : self::json($changes),
            'suppressed_by_monitor_id' => $suppressedBy,
            'created_at' => $at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stateRow(int $monitorId, AlertSnapshot $state, CarbonImmutable $now): array
    {
        return [
            'monitor_id' => $monitorId,
            'notified' => $state->notified?->value,
            'observed' => $state->observed?->value,
            'current' => $state->current?->value,
            'problem_since' => $state->problemSince,
            'down_since' => $state->downSince,
            'degraded_since' => $state->degradedSince,
            'flapping' => $state->flapping,
            'flapping_since' => $state->flappingSince,
            'recent_changes' => $state->recentChanges === [] ? null : self::json($state->recentChanges),
            'last_notified_at' => $state->lastNotifiedAt,
            'reminders_sent' => $state->remindersSent,
            'suppressed' => $state->suppressed,
            'last_signal_at' => $state->lastSignalAt,
            'due_at' => $state->dueAt,
            'updated_at' => $now,
        ];
    }

    private function snapshot(stdClass $row): AlertSnapshot
    {
        $changes = json_decode(is_string($row->recent_changes) ? $row->recent_changes : '[]', true);

        return new AlertSnapshot(
            AlertClass::tryFrom(self::string($row->notified)),
            AlertClass::tryFrom(self::string($row->observed)),
            MonitorStatus::tryFrom(self::string($row->current)),
            self::time($row->problem_since),
            self::time($row->down_since),
            self::time($row->degraded_since),
            (bool) $row->flapping,
            self::time($row->flapping_since),
            is_array($changes) ? array_values(array_map(static fn (mixed $at): int => self::int($at), $changes)) : [],
            self::time($row->last_notified_at),
            self::int($row->reminders_sent),
            (bool) $row->suppressed,
            self::time($row->last_signal_at),
            self::time($row->due_at),
        );
    }

    private static function withDueAt(AlertSnapshot $s, ?CarbonImmutable $dueAt): AlertSnapshot
    {
        return new AlertSnapshot(
            $s->notified, $s->observed, $s->current, $s->problemSince, $s->downSince, $s->degradedSince, $s->flapping,
            $s->flappingSince, $s->recentChanges, $s->lastNotifiedAt, $s->remindersSent, $s->suppressed, $s->lastSignalAt, $dueAt,
        );
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, 'UTC') : null;
    }

    /**
     * @param  array<mixed>  $value
     */
    private static function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException) {
            return '[]';
        }
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
