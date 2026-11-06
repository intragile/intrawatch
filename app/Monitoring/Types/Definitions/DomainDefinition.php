<?php

namespace App\Monitoring\Types\Definitions;

use App\Enums\MonitorType;
use App\Monitoring\Domain\RegistrableDomains;
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
            'domain' => ['required', 'string', function (string $a, mixed $v, \Closure $fail): void {
                // Internationalised names are checked in their ASCII (punycode) form.
                $ascii = is_string($v) && preg_match('/[^\x00-\x7F]/', $v) === 1
                    ? idn_to_ascii($v, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46)
                    : $v;
                if (! is_string($v) || ! is_string($ascii) || Hostname::isIp($ascii) || ! Hostname::isHostname($ascii, 2)) {
                    $fail('The :attribute must be a domain name with at least two labels.');
                } elseif (app(RegistrableDomains::class)->registrable($v) === null) {
                    // Public suffixes such as co.uk, and names under an unknown suffix, have no registration.
                    $fail('The :attribute must be a registrable domain name.');
                }
            }],
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
