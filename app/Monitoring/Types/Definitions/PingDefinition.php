<?php

namespace App\Monitoring\Types\Definitions;

use App\Enums\MonitorType;
use App\Monitoring\Types\Hostname;

class PingDefinition extends AbstractDefinition
{
    public function type(): MonitorType
    {
        return MonitorType::Ping;
    }

    public function destinationKeys(): array
    {
        return ['host'];
    }

    public function configRules(): array
    {
        return [
            'host' => ['required', 'string', fn (string $a, mixed $v, \Closure $fail) => is_string($v) && Hostname::isHostOrIp($v) ?: $fail('The :attribute must be a hostname or IP address.')],
            'count' => ['required', 'integer', 'between:1,5'],
        ];
    }

    public function defaults(): array
    {
        return ['count' => 1];
    }
}
