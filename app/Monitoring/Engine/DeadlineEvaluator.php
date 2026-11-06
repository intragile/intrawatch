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
}
