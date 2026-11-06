<?php

namespace App\Monitoring\Types\Definitions;

use App\Enums\MonitorType;
use App\Monitoring\Types\Hostname;

class DomainDefinition extends AbstractDefinition
{
    public function type(): MonitorType
    {
        return MonitorType::Domain;
    }

    public function configRules(): array
    {
        return [
            'domain' => ['required', 'string', fn (string $a, mixed $v, \Closure $fail) => is_string($v) && ! Hostname::isIp($v) && Hostname::isHostname($v, 2) ?: $fail('The :attribute must be a domain name with at least two labels.')],
            'expiry_days' => ['required', 'array', 'min:1', 'max:10'],
            'expiry_days.*' => ['integer', 'between:1,3650'],
            'degraded_below_days' => ['required', 'integer', 'between:0,3650'],
        ];
    }

    public function defaults(): array
    {
        return [
            'expiry_days' => $this->defaultExpiryDays(),
            'degraded_below_days' => $this->defaultDegradedBelowDays(),
        ];
    }

    public function defaultIntervalSeconds(): int
    {
        return config()->integer('monitoring.limits.interval.slow_default');
    }

    public function minIntervalSeconds(): int
    {
        return config()->integer('monitoring.limits.interval.domain_min');
    }

    public function supportsInverted(): bool
    {
        return false;
    }
}
