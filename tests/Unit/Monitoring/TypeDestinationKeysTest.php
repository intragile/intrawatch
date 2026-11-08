<?php

namespace Tests\Unit\Monitoring;

use App\Enums\MonitorType;
use App\Monitoring\Types\TypeRegistry;
use Tests\TestCase;

/**
 * Every monitor type names the config keys that decide where its checks (and so its secrets) go. A forgotten
 * type or a misspelt key must fail here rather than silently weaken the re-entry check on update.
 */
class TypeDestinationKeysTest extends TestCase
{
    private const DESTINATIONS = [
        'http' => ['url', 'proxy.url'],
        'tcp' => ['host', 'port'],
        'ping' => ['host'],
        'dns' => ['name', 'resolver'],
        'tls' => ['host', 'port', 'servername'],
        'domain' => ['domain'],
        'push' => [],
        'cron' => [],
        'group' => [],
    ];

    public function test_every_type_declares_its_destination_keys(): void
    {
        $this->assertCount(count(MonitorType::cases()), self::DESTINATIONS);
        foreach (TypeRegistry::all() as $definition) {
            $type = $definition->type()->value;
            $this->assertArrayHasKey($type, self::DESTINATIONS, "{$type} has no expected destination keys");
            $this->assertSame(self::DESTINATIONS[$type], $definition->destinationKeys(), "destination keys of {$type}");
        }
    }

    public function test_declared_keys_are_config_keys_of_the_type(): void
    {
        foreach (TypeRegistry::all() as $definition) {
            $rules = array_keys($definition->configRules());
            foreach ($definition->destinationKeys() as $key) {
                $this->assertContains($key, $rules, "{$key} is not a config key of {$definition->type()->value}");
            }
        }
    }
}
