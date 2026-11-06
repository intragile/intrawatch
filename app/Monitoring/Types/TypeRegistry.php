<?php

namespace App\Monitoring\Types;

use App\Enums\MonitorType;
use App\Monitoring\Types\Definitions\CronDefinition;
use App\Monitoring\Types\Definitions\DnsDefinition;
use App\Monitoring\Types\Definitions\DomainDefinition;
use App\Monitoring\Types\Definitions\GroupDefinition;
use App\Monitoring\Types\Definitions\HttpDefinition;
use App\Monitoring\Types\Definitions\PingDefinition;
use App\Monitoring\Types\Definitions\PushDefinition;
use App\Monitoring\Types\Definitions\TcpDefinition;
use App\Monitoring\Types\Definitions\TlsDefinition;

final class TypeRegistry
{
    /** @var array<string, TypeDefinition> */
    private static array $instances = [];

    public static function for(MonitorType $type): TypeDefinition
    {
        return self::$instances[$type->value] ??= match ($type) {
            MonitorType::Http => new HttpDefinition,
            MonitorType::Tcp => new TcpDefinition,
            MonitorType::Ping => new PingDefinition,
            MonitorType::Dns => new DnsDefinition,
            MonitorType::Tls => new TlsDefinition,
            MonitorType::Domain => new DomainDefinition,
            MonitorType::Push => new PushDefinition,
            MonitorType::Cron => new CronDefinition,
            MonitorType::Group => new GroupDefinition,
        };
    }

    /**
     * @return list<TypeDefinition>
     */
    public static function all(): array
    {
        return array_map(self::for(...), MonitorType::cases());
    }
}
