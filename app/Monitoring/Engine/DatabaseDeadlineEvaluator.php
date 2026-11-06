<?php

namespace App\Monitoring\Engine;

use App\Enums\MonitorType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Evaluates push and cron deadlines against the monitor_states table.
 *
 * Runs inside the worker's event loop and is one of the few callers allowed to block it on the database,
 * for reads only: the state rows of every monitor due in one scheduler tick are fetched with one query per
 * chunk of 500, never one query per monitor. Outcomes go through the ResultRecorder like any check outcome.
 *
 * A missed deadline is only ever reported as down when the state row says so; when it cannot be read, the
 * caller retries later and nothing is recorded. Deadlines that passed while the worker could not see them
 * (before it started, or during a database outage) are moved by reanchor(), which persists the new anchor:
 * evaluation itself only ever reads the stored state, so nothing it decides depends on worker memory.
 */
final class DatabaseDeadlineEvaluator implements DeadlineEvaluator
{
    private const CHUNK = 500;

    public function evaluate(MonitorSpec $spec, CarbonImmutable $now): array
    {
        return $this->evaluateMany([$spec], $now)[$spec->id];
    }

    public function evaluateMany(array $specs, CarbonImmutable $now): array
    {
        $byId = $this->byId($specs);
        $states = $this->states(array_keys($byId));

        $results = [];
        foreach ($byId as $id => $spec) {
            $state = $states[$id] ?? null;
            $results[$id] = match (true) {
                $state === null => [null, $now->addSeconds($spec->intervalSeconds)],
                $spec->type === MonitorType::Push => $this->push($spec, $state, $now),
                $spec->type === MonitorType::Cron => $this->cron($spec, $state, $now),
                default => [null, $now->addSeconds($spec->intervalSeconds)],
            };
        }

        return $results;
    }

    /**
     * Reads the state rows in batches, picks the monitors whose current deadline falls in the window, and
     * moves them with one batched update per chunk. The updates never move an anchor backwards, so a report
     * that arrived after the read wins.
     */
    public function reanchor(array $specs, ?CarbonImmutable $from, CarbonImmutable $to): int
    {
        $to = $to->startOfSecond();
        $from = $from?->startOfSecond();
        $inside = static fn (CarbonImmutable $at): bool => $at->getTimestamp() < $to->getTimestamp()
            && ($from === null || $at->getTimestamp() >= $from->getTimestamp());

        $byId = $this->byId($specs);
        $anchors = [];
        $runs = [];
        foreach ($this->states(array_keys($byId)) as $id => $state) {
            $spec = $byId[$id] ?? null;
            if ($spec === null) {
                continue;
            }
            if ($spec->type === MonitorType::Push) {
                $deadline = $state->anchor(MonitorType::Push)->addSeconds($this->pushPeriod($spec));
                $missed = $state->missDeadlineAt !== null && $state->missDeadlineAt->getTimestamp() >= $deadline->getTimestamp();
                if ($inside($deadline) && ! $missed && ! $state->reported($deadline)) {
                    $anchors[] = $id;
                }
            } elseif ($spec->type === MonitorType::Cron) {
                $maxRuntime = $this->int($spec->config['max_runtime_seconds'] ?? null, null);
                $limit = $state->runStartedAt !== null && $maxRuntime !== null ? $state->runStartedAt->addSeconds($maxRuntime) : null;
                if ($limit !== null && $inside($limit) && ! $state->reported($limit)) {
                    // The finish may have been lost: the run counts as started at the recovery.
                    $runs[] = $id;
                }
                $due = $this->cronDue($spec, $state);
                if ($due !== null && $inside($due) && ! $state->reported($due)) {
                    $anchors[] = $id;
                }
            }
        }

        if ($anchors === [] && $runs === []) {
            return 0;
        }

        DB::transaction(function () use ($anchors, $runs, $to): void {
            foreach (array_chunk($anchors, self::CHUNK) as $chunk) {
                DB::table('monitor_states')->whereIn('monitor_id', $chunk)
                    ->where(fn (Builder $query) => $query->whereNull('deadline_anchor_at')->orWhere('deadline_anchor_at', '<', $to))
                    ->update(['deadline_anchor_at' => $to]);
            }
            foreach (array_chunk($runs, self::CHUNK) as $chunk) {
                DB::table('monitor_states')->whereIn('monitor_id', $chunk)->where('run_started_at', '<', $to)
                    ->update(['run_started_at' => $to]);
            }
        });

        return count(array_unique([...$anchors, ...$runs]));
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
     * @param  list<MonitorSpec>  $specs
     * @return array<int, MonitorSpec>
     */
    private function byId(array $specs): array
    {
        $byId = [];
        foreach ($specs as $spec) {
            $byId[$spec->id] = $spec;
        }

        return $byId;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, DeadlineState>
     */
    private function states(array $ids): array
    {
        $states = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = DB::table('monitor_states')->whereIn('monitor_id', $chunk)->get(['monitor_id', ...DeadlineState::COLUMNS]);
            foreach ($rows as $row) {
                $id = is_numeric($row->monitor_id) ? (int) $row->monitor_id : 0;
                $states[$id] = DeadlineState::fromRow($row);
            }
        }

        return $states;
    }

    private function pushPeriod(MonitorSpec $spec): int
    {
        return $spec->intervalSeconds + $this->int($spec->config['grace_seconds'] ?? null, 0);
    }

    /**
     * When the run expected after the cron monitor's anchor is missed, grace included; null when the
     * expression is invalid or has no next run.
     */
    private function cronDue(MonitorSpec $spec, DeadlineState $state): ?CarbonImmutable
    {
        $schedule = CronSchedule::parse(is_string($spec->config['schedule'] ?? null) ? $spec->config['schedule'] : '', $this->timezone($spec));

        return $schedule?->expectedAfter($state->anchor(MonitorType::Cron))
            ?->addSeconds($this->int($spec->config['grace_seconds'] ?? null, 0));
    }

    /**
     * @return array{0: ?CheckOutcome, 1: CarbonImmutable}
     */
    private function push(MonitorSpec $spec, DeadlineState $state, CarbonImmutable $now): array
    {
        $anchor = $state->anchor(MonitorType::Push);
        $deadline = $anchor->addSeconds($this->pushPeriod($spec));
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
    private function cron(MonitorSpec $spec, DeadlineState $state, CarbonImmutable $now): array
    {
        // A run can start at any time, so the monitor is looked at least once per interval for an overrun.
        $poll = $now->addSeconds($spec->intervalSeconds);
        $next = [$poll];
        $anchor = $state->anchor(MonitorType::Cron);

        $maxRuntime = $this->int($spec->config['max_runtime_seconds'] ?? null, null);
        if ($state->runStartedAt !== null && $maxRuntime !== null) {
            $limit = $state->runStartedAt->addSeconds($maxRuntime);
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
        $grace = $this->int($spec->config['grace_seconds'] ?? null, 0);
        $expected = $schedule?->expectedAfter($anchor);
        if ($expected !== null) {
            $due = $expected->addSeconds($grace);
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
