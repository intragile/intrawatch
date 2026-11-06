<?php

namespace App\Monitoring\Engine;

use Carbon\CarbonImmutable;

/**
 * Never records anything and looks again one interval later.
 */
final class NullDeadlineEvaluator implements DeadlineEvaluator
{
    public function evaluate(MonitorSpec $spec, CarbonImmutable $now): array
    {
        return [null, $now->addSeconds($spec->intervalSeconds)];
    }
}
