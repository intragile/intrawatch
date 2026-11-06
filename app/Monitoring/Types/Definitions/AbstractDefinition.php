<?php

namespace App\Monitoring\Types\Definitions;

use App\Monitoring\Types\TypeDefinition;
use Illuminate\Validation\Validator;

/**
 * Neutral defaults for the traits most types share; a type overrides only what differs.
 */
abstract class AbstractDefinition implements TypeDefinition
{
    public function secretRules(): array
    {
        return [];
    }

    public function defaults(): array
    {
        return [];
    }

    public function defaultIntervalSeconds(): int
    {
        return config()->integer('monitoring.limits.interval.default');
    }

    public function minIntervalSeconds(): int
    {
        return config()->integer('monitoring.limits.interval.min');
    }

    public function supportsInverted(): bool
    {
        return true;
    }

    public function isScheduled(): bool
    {
        return true;
    }

    public function afterValidation(array $config, Validator $v): void {}

    /**
     * @return list<int>
     */
    protected function defaultExpiryDays(): array
    {
        $days = [];
        foreach (config()->array('monitoring.thresholds.expiry_days') as $day) {
            $days[] = is_numeric($day) ? (int) $day : 0;
        }

        return $days;
    }

    protected function defaultDegradedBelowDays(): int
    {
        return config()->integer('monitoring.thresholds.degraded_below_days');
    }
}
