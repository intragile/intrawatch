<?php

namespace App\Monitoring\Engine;

use Carbon\CarbonImmutable;

/**
 * Evaluates monitors that are not polled but expected to report in, such as push and cron monitors.
 */
interface DeadlineEvaluator
{
    /**
     * @param  ?Availability  $availability  When the caller could see reports; deadlines it could not see count from later.
     * @return array{0: ?CheckOutcome, 1: CarbonImmutable} The outcome to record, if any, and when to evaluate next.
     */
    public function evaluate(MonitorSpec $spec, CarbonImmutable $now, ?Availability $availability = null): array;

    /**
     * Evaluates several monitors at once, reading their state together.
     *
     * @param  list<MonitorSpec>  $specs
     * @return array<int, array{0: ?CheckOutcome, 1: CarbonImmutable}> Keyed by monitor id.
     */
    public function evaluateMany(array $specs, CarbonImmutable $now, ?Availability $availability = null): array;
}
