<?php

namespace App\Enums;

use App\Monitoring\Types\TypeDefinition;
use App\Monitoring\Types\TypeRegistry;

enum MonitorType: string
{
    case Http = 'http';
    case Tcp = 'tcp';
    case Ping = 'ping';
    case Dns = 'dns';
    case Tls = 'tls';
    case Domain = 'domain';
    case Push = 'push';
    case Cron = 'cron';
    case Group = 'group';

    public function definition(): TypeDefinition
    {
        return TypeRegistry::for($this);
    }
}
