<?php

namespace App\Monitoring\Engine;

use App\Enums\MonitorType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Evaluates push and cron deadlines against the monitor_states table.
 *
 * Runs inside the worker's event loop and is one of the few callers allowed to block it on the database,
 * for reads only: the state rows of every monitor due in one scheduler tick are fetched with one query per
 * chunk of 500, never one query per monitor. Outcomes go through the ResultRecorder like any check outcome.
 *
 * A missed deadline is only ever reported as down when the state row says so; when it cannot be read, the
 * caller retries later and nothing is recorded. Deadlines that passed before $availableSince (the moment the
 * worker started or saw the database come back) are moved to start from that moment instead.
 */
final class DatabaseDeadlineEvaluator implements DeadlineEvaluator
{
    private const CHUNK = 500;

    public function evaluate(MonitorSpec $spec, CarbonImmutable $now, ?CarbonImmutable $availableSince = null): array
    {
        return $this->evaluateMany([$spec], $now, $availableSince)[$spec->id];
    }

    public function evaluateMany(array $specs, CarbonImmutable $now, ?CarbonImmutable $availableSince = null): array
    {
        /** @var array<int, MonitorSpec> $byId */
        $byId = [];
        foreach ($specs as $spec) {
            $byId[$spec->id] = $spec;
        }

        /** @var array<int, DeadlineState> $states */
        $states = [];
        foreach (array_chunk(array_keys($byId), self::CHUNK) as $chunk) {
            $rows = DB::table('monitor_states')->whereIn('monitor_id', $chunk)->get(['monitor_id', ...DeadlineState::COLUMNS]);
            foreach ($rows as $row) {
                $id = is_numeric($row->monitor_id) ? (int) $row->monitor_id : 0;
                $states[$id] = DeadlineState::fromRow($row);
            }
        }

        $results = [];
        foreach ($byId as $id => $spec) {
            $state = $states[$id] ?? null;
            $results[$id] = match (true) {
                $state === null => [null, $now->addSeconds($spec->intervalSeconds)],
                $spec->type === MonitorType::Push => $this->push($spec, $state, $now, $availableSince),
                $spec->type === MonitorType::Cron => $this->cron($spec, $state, $now, $availableSince),
                default => [null, $now->addSeconds($spec->intervalSeconds)],
            };
        }

        return $results;
    }

    /**
     * The run expected next after a report (or creation, resume or edit) at $after, in $timezone, or null
     * when the expression has no next run. See CronSchedule for daylight saving time and early reports.
     */
    public static function expectedRun(string $schedule, string $timezone, CarbonImmutable $after): ?CarbonImmutable
    {
        return CronSchedule::parse($schedule, $timezone)?->expectedAfter($after);
    }

    /**
     * @return array{0: ?CheckOutcome, 1: CarbonImmutable}
     */
    private function push(MonitorSpec $spec, DeadlineState $state, CarbonImmutable $now, ?CarbonImmutable $availableSince): array
    {
        $anchor = $state->anchor(MonitorType::Push);
        $period = $spec->intervalSeconds + $this->int($spec->config['grace_seconds'] ?? null, 0);
        $deadline = $anchor->addSeconds($period);
        if ($availableSince !== null && $deadline->lt($availableSince)) {
            // Missed while this worker could not see the monitor (not running yet, or the database was
            // unavailable): its heartbeats may have been lost on the way, so it gets a full period from then.
            $deadline = $availableSince->addSeconds($period);
        }
        if ($now->lte($deadline)) {
            return [null, $deadline->addSecond()];
        }

        // Miss k is due after deadline + k * retry interval: the retries are spent by time, not by how often
        // the deadline happens to be evaluated.
        $retry = max(1, $spec->retryIntervalSeconds);
        $miss = intdiv(max(0, $now->getTimestamp() - $deadline->getTimestamp() - 1), $retry);
        $next = $deadline->addSeconds(($miss + 1) * $retry + 1);
        if ($state->reported($deadline) || $state->missRecorded($deadline, $miss)) {
            return [null, $next];
        }

        $silent = $now->getTimestamp() - $anchor->getTimestamp();
        $outcome = CheckOutcome::down("No heartbeat for {$silent} s", facts: ['deadline' => $deadline->toIso8601ZuluString()])
            ->forDeadline(new Deadline($deadline, $anchor, null, $miss));

        return [$outcome, $next];
    }

    /**
     * @return array{0: ?CheckOutcome, 1: CarbonImmutable}
     */
    private function cron(MonitorSpec $spec, DeadlineState $state, CarbonImmutable $now, ?CarbonImmutable $availableSince): array
    {
        // A run can start at any time, so the monitor is looked at least once per interval for an overrun.
        $poll = $now->addSeconds($spec->intervalSeconds);
        $next = [$poll];
        $anchor = $state->anchor(MonitorType::Cron);

        $maxRuntime = $this->int($spec->config['max_runtime_seconds'] ?? null, null);
        if ($state->runStartedAt !== null && $maxRuntime !== null) {
            $limit = $state->runStartedAt->addSeconds($maxRuntime);
            if ($availableSince !== null && $limit->lt($availableSince)) {
                // The finish may have been lost while this worker could not see the monitor.
                $limit = $availableSince->addSeconds($maxRuntime);
            }
            if ($now->lte($limit)) {
                $next[] = $limit->addSecond();
            } elseif (! $state->reported($limit)) {
                $outcome = CheckOutcome::down("Run exceeded {$maxRuntime} s", facts: ['run_started_at' => $state->runStartedAt->toIso8601ZuluString()])
                    ->final()
                    ->forDeadline(new Deadline($limit, $anchor, $state->runStartedAt));

                return [$outcome, $poll];
            }
        }

        $schedule = CronSchedule::parse(is_string($spec->config['schedule'] ?? null) ? $spec->config['schedule'] : '', $this->timezone($spec));
        $expected = $schedule?->expectedAfter($anchor);
        if ($schedule !== null && $expected !== null && $availableSince !== null && $expected->lt($availableSince)) {
            // Runs scheduled before this worker could see the monitor are not reported: their pings may
            // have been lost on the way.
            $expected = $schedule->nextAfter($availableSince->subSecond());
        }
        if ($expected !== null) {
            $due = $expected->addSeconds($this->int($spec->config['grace_seconds'] ?? null, 0));
            if ($now->lte($due)) {
                $next[] = $due->addSecond();
            } elseif (! $state->reported($due)) {
                $outcome = CheckOutcome::down("Missed run expected at {$expected->toIso8601String()}", facts: ['expected_at' => $expected->toIso8601String()])
                    ->final()
                    ->forDeadline(new Deadline($due, $anchor));

                return [$outcome, $poll];
            }
        }

        return [null, min($next)];
    }

    /**
     * The monitor's configured timezone, or the application timezone when none is set.
     */
    private function timezone(MonitorSpec $spec): string
    {
        $timezone = $spec->config['timezone'] ?? null;

        return is_string($timezone) && $timezone !== '' ? $timezone : config()->string('app.timezone', 'UTC');
    }

    /**
     * @template T of int|null
     *
     * @param  T  $default
     * @return int|T
     */
    private function int(mixed $value, ?int $default): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : $default;
    }
}
