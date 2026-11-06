<?php

namespace App\Monitoring\Types\Definitions;

use App\Enums\MonitorType;
use App\Monitoring\Types\Hostname;

class DnsDefinition extends AbstractDefinition
{
    public function type(): MonitorType
    {
        return MonitorType::Dns;
    }

    public function configRules(): array
    {
        return [
            'name' => ['required', 'string', fn (string $a, mixed $v, \Closure $fail) => is_string($v) && Hostname::isHostname($v) ?: $fail('The :attribute must be a valid DNS name.')],
            'record_type' => ['required', 'in:A,AAAA,CNAME,MX,NS,TXT,SOA,SRV,CAA,PTR'],
            'resolver' => ['nullable', 'string', fn (string $a, mixed $v, \Closure $fail) => $v === null || (is_string($v) && Hostname::isResolverAddress($v)) ?: $fail('The :attribute must be an IP address, optionally with a port.')],
            'expected' => ['nullable', 'array', 'max:50'],
            'expected.*' => ['string', 'max:1024'],
            'match' => ['required', 'in:any,all,exact'],
            'watch_changes' => ['required', 'boolean'],
        ];
    }

    public function defaults(): array
    {
        return ['resolver' => null, 'expected' => null, 'match' => 'any', 'watch_changes' => false];
    }
}
