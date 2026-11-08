<?php

namespace Tests\Unit\Alerting;

use App\Alerting\Channels\ChannelRegistry;
use App\Enums\ChannelType;
use Tests\TestCase;

/**
 * Every channel type names the config keys that decide where its secrets are sent, and the config keys that are
 * themselves sensitive. A forgotten type or a misspelt key must fail here rather than silently weaken a check.
 */
class ChannelDestinationKeysTest extends TestCase
{
    private const DESTINATIONS = [
        'webhook' => ['url'],
        'telegram' => ['server_url'],
        'ntfy' => ['server_url'],
        'gotify' => ['server_url'],
        'apprise' => ['server_url'],
        'matrix' => ['homeserver_url'],
        'email' => ['smtp.host', 'smtp.port', 'smtp.encryption'],
        'opsgenie' => ['region'],
        'slack' => [],
        'discord' => [],
        'teams' => [],
        'mattermost' => [],
        'google_chat' => [],
        'pagerduty' => [],
        'pushover' => [],
    ];

    private const SENSITIVE = [
        'webhook' => ['url'],
        'ntfy' => ['topic'],
        'apprise' => ['config_key'],
    ];

    public function test_every_type_declares_its_destination_keys(): void
    {
        $this->assertCount(count(ChannelType::cases()), self::DESTINATIONS);
        foreach (ChannelRegistry::all() as $definition) {
            $type = $definition->type()->value;
            $this->assertArrayHasKey($type, self::DESTINATIONS, "{$type} has no expected destination keys");
            $this->assertSame(self::DESTINATIONS[$type], $definition->destinationKeys(), "destination keys of {$type}");
        }
    }

    public function test_every_type_declares_its_sensitive_config_keys(): void
    {
        foreach (ChannelRegistry::all() as $definition) {
            $type = $definition->type()->value;
            $this->assertSame(self::SENSITIVE[$type] ?? [], $definition->sensitiveConfigKeys(), "sensitive keys of {$type}");
        }
    }

    public function test_declared_keys_are_config_keys_of_the_type(): void
    {
        foreach (ChannelRegistry::all() as $definition) {
            $rules = array_keys($definition->configRules());
            foreach ([...$definition->destinationKeys(), ...$definition->sensitiveConfigKeys()] as $key) {
                $this->assertContains($key, $rules, "{$key} is not a config key of {$definition->type()->value}");
            }
        }
    }
}
