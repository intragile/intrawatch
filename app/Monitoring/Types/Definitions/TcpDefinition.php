<?php

namespace App\Monitoring\Types\Definitions;

use App\Enums\MonitorType;
use App\Monitoring\Types\Hostname;

class TcpDefinition extends AbstractDefinition
{
    public function type(): MonitorType
    {
        return MonitorType::Tcp;
    }

    public function destinationKeys(): array
    {
        return ['host', 'port'];
    }

    public function configRules(): array
    {
        return [
            'host' => ['required', 'string', fn (string $a, mixed $v, \Closure $fail) => is_string($v) && Hostname::isHostOrIp($v) ?: $fail('The :attribute must be a hostname or IP address.')],
            'port' => ['required', 'integer', 'between:1,65535'],
        ];
    }
}
