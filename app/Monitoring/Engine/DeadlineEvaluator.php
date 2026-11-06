<?php

namespace App\Monitoring\Engine;

use Carbon\CarbonImmutable;

/**
 * Evaluates monitors that are not polled but expected to report in, such as push and cron monitors.
 */
interface DeadlineEvaluator
{
    /**
     * @param  ?CarbonImmutable  $availableSince  Since when the caller has been able to see reports: deadlines that passed
     *                                            earlier count from this moment instead.
     * @return array{0: ?CheckOutcome, 1: CarbonImmutable} The outcome to record, if any, and when to evaluate next.
     */
    public function evaluate(MonitorSpec $spec, CarbonImmutable $now, ?CarbonImmutable $availableSince = null): array;

    /**
     * Evaluates several monitors at once, reading their state together.
     *
     * @param  list<MonitorSpec>  $specs
     * @return array<int, array{0: ?CheckOutcome, 1: CarbonImmutable}> Keyed by monitor id.
     */
    public function evaluateMany(array $specs, CarbonImmutable $now, ?CarbonImmutable $availableSince = null): array;
}
