<?php

namespace App\Monitoring\Engine;

use Carbon\CarbonImmutable;

/**
 * Evaluates monitors that are not polled but expected to report in, such as push and cron monitors.
 */
interface DeadlineEvaluator
{
    /**
     * @return array{0: ?CheckOutcome, 1: CarbonImmutable} The outcome to record, if any, and when to evaluate next.
     */
    public function evaluate(MonitorSpec $spec, CarbonImmutable $now): array;

    /**
     * Evaluates several monitors at once, reading their state together.
     *
     * @param  list<MonitorSpec>  $specs
     * @return array<int, array{0: ?CheckOutcome, 1: CarbonImmutable}> Keyed by monitor id.
     */
    public function evaluateMany(array $specs, CarbonImmutable $now): array;

    /**
     * Moves the deadlines that fell due in [$from, $to) to count from $to, for the monitors whose reports may
     * have been lost meanwhile. A deadline already reported down, or with a miss recorded, keeps counting.
     *
     * @param  list<MonitorSpec>  $specs
     * @param  ?CarbonImmutable  $from  Start of the window; null for every deadline before $to.
     * @return int How many monitors were moved.
     */
    public function reanchor(array $specs, ?CarbonImmutable $from, CarbonImmutable $to): int;
}
