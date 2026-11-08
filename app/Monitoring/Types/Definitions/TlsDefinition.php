<?php

namespace App\Monitoring\Types\Definitions;

use App\Enums\MonitorType;
use App\Monitoring\Types\Hostname;

class TlsDefinition extends AbstractDefinition
{
    public function type(): MonitorType
    {
        return MonitorType::Tls;
    }

    public function destinationKeys(): array
    {
        return ['host', 'port', 'servername'];
    }

    public function configRules(): array
    {
        return [
            'host' => ['required', 'string', fn (string $a, mixed $v, \Closure $fail) => is_string($v) && Hostname::isHostOrIp($v) ?: $fail('The :attribute must be a hostname or IP address.')],
            'port' => ['required', 'integer', 'between:1,65535'],
            'starttls' => ['required', 'in:none,smtp,imap,pop3'],
            'servername' => ['nullable', 'string', fn (string $a, mixed $v, \Closure $fail) => $v === null || (is_string($v) && Hostname::isHostname($v)) ?: $fail('The :attribute must be a valid hostname.')],
            'expiry_days' => ['required', 'array', 'min:1', 'max:10'],
            'expiry_days.*' => ['integer', 'between:1,3650'],
            'degraded_below_days' => ['required', 'integer', 'between:0,3650'],
            'check_protocols' => ['required', 'boolean'],
            'check_ciphers' => ['required', 'boolean'],
            'watch_certificate' => ['required', 'boolean'],
        ];
    }

    public function defaults(): array
    {
        return [
            'port' => 443,
            'starttls' => 'none',
            'servername' => null,
            'expiry_days' => $this->defaultExpiryDays(),
            'degraded_below_days' => $this->defaultDegradedBelowDays(),
            'check_protocols' => true,
            'check_ciphers' => true,
            'watch_certificate' => true,
        ];
    }

    public function defaultIntervalSeconds(): int
    {
        return config()->integer('monitoring.limits.interval.slow_default');
    }

    public function supportsInverted(): bool
    {
        return false;
    }
}
