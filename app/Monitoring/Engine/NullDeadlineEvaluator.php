<?php

namespace App\Monitoring\Engine;

use Carbon\CarbonImmutable;

/**
 * Never records anything and looks again one interval later.
 */
final class NullDeadlineEvaluator implements DeadlineEvaluator
{
    public function evaluate(MonitorSpec $spec, CarbonImmutable $now, ?CarbonImmutable $availableSince = null): array
    {
        return [null, $now->addSeconds($spec->intervalSeconds)];
    }

    public function evaluateMany(array $specs, CarbonImmutable $now, ?CarbonImmutable $availableSince = null): array
    {
        $results = [];
        foreach ($specs as $spec) {
            $results[$spec->id] = $this->evaluate($spec, $now);
        }

        return $results;
    }
}
