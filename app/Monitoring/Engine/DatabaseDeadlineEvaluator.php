<?php

namespace App\Monitoring\Engine;

use App\Enums\MonitorType;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Evaluates push and cron deadlines against the monitor_states table.
 *
 * Runs inside the worker's event loop and is one of the few callers allowed to block it on the database,
 * for reads only: the state rows of every monitor due in one scheduler tick are fetched with one query per
 * chunk of 500, never one query per monitor. Outcomes go through the ResultRecorder like any check outcome.
 *
 * A missed deadline is only ever reported as down when the state row says so; when it cannot be read, the
 * caller retries later and nothing is recorded.
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
                $spec->type === MonitorType::Push => $this->push($spec, $state, $now),
                $spec->type === MonitorType::Cron => $this->cron($spec, $state, $now),
                default => [null, $now->addSeconds($spec->intervalSeconds)],
            };
        }

        return $results;
    }

    /**
     * The first run of $schedule after $after, in $timezone, or null when the expression has no next run.
     *
     * Daylight saving time follows cron daemons such as cronie:
     * - A run in the skipped hour (spring forward) is expected right after the jump, which is what
     *   cron-expression returns: '0 2 * * *' in Europe/Budapest is expected at 03:00 CEST that day.
     * - A wall-clock time that occurs twice (fall back) is expected once. Cron-expression would expect
     *   '0 2 * * *' again at 02:00 CET after the run at 02:00 CEST; when the next run has the same local
     *   date and time as the previous one, it is skipped and the one after it is expected instead. A job
     *   that does run twice (for instance hourly) is then merely expected less often, never falsely missed.
     */
    public static function expectedRun(string $schedule, string $timezone, CarbonImmutable $after): ?CarbonImmutable
    {
        try {
            $cron = new CronExpression($schedule);
            $next = CarbonImmutable::instance($cron->getNextRunDate($after, 0, false, $timezone));
        } catch (Throwable) {
            return null;
        }

        try {
            $previous = CarbonImmutable::instance($cron->getPreviousRunDate($after, 0, true, $timezone));
            if ($previous->lt($next) && $previous->format('Y-m-d H:i') === $next->format('Y-m-d H:i')) {
                $next = CarbonImmutable::instance($cron->getNextRunDate($next, 0, false, $timezone));
            }
        } catch (Throwable) {
            // No previous run: nothing can repeat.
        }

        return $next->setTimezone($timezone);
    }

    /**
     * @return array{0: ?CheckOutcome, 1: CarbonImmutable}
     */
    private function push(MonitorSpec $spec, DeadlineState $state, CarbonImmutable $now): array
    {
        $anchor = $state->anchor(MonitorType::Push);
        $deadline = $anchor->addSeconds($spec->intervalSeconds + $this->int($spec->config['grace_seconds'] ?? null, 0));
        if ($now->lte($deadline)) {
            return [null, $deadline->addSecond()];
        }

        $later = $now->addSeconds($spec->intervalSeconds);
        if ($state->reported($deadline)) {
            return [null, $later];
        }

        $silent = $now->getTimestamp() - $anchor->getTimestamp();
        $outcome = CheckOutcome::down("No heartbeat for {$silent} s", facts: ['deadline' => $deadline->toIso8601ZuluString()])
            ->forDeadline(new Deadline($deadline, $anchor));

        return [$outcome, $later];
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

        $schedule = is_string($spec->config['schedule'] ?? null) ? $spec->config['schedule'] : '';
        $expected = self::expectedRun($schedule, $this->timezone($spec), $anchor);
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
