<?php

namespace Tests\Feature\Api;

use App\Alerting\Channels\ChannelRegistry;
use App\Models\User;
use App\Monitoring\Types\TypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * The type catalogues publish each type's destination keys, so the SPA never keeps its own copy of them.
 */
final class TypeEndpointsDestinationKeysTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_types_publish_destination_keys(): void
    {
        $response = $this->actingAs(User::factory()->create())->getJson('/api/v1/monitor-types')->assertOk();
        $byType = $this->destinationKeysByType($response->json());

        foreach (TypeRegistry::all() as $definition) {
            $type = $definition->type()->value;
            $this->assertSame($definition->destinationKeys(), $byType[$type], "monitor type {$type}");
        }

        $this->assertSame(['url', 'proxy.url'], $byType['http']);
        $this->assertSame([], $byType['push']);
        $this->assertStringContainsString('"destination_keys":[]', (string) $response->getContent());
    }

    public function test_channel_types_publish_destination_keys(): void
    {
        $response = $this->actingAs(User::factory()->create())->getJson('/api/v1/notification-channel-types')->assertOk();
        $byType = $this->destinationKeysByType($response->json());

        foreach (ChannelRegistry::all() as $definition) {
            $type = $definition->type()->value;
            $this->assertSame($definition->destinationKeys(), $byType[$type], "channel type {$type}");
        }

        $this->assertSame(['url'], $byType['webhook']);
        $this->assertSame(['smtp.host', 'smtp.port', 'smtp.encryption'], $byType['email']);
        $this->assertSame([], $byType['slack']);
    }

    /**
     * Each catalogue row's destination keys, keyed by type.
     *
     * @return array<string, mixed>
     */
    private function destinationKeysByType(mixed $rows): array
    {
        if (! is_array($rows)) {
            throw new LogicException('The catalogue is not a JSON array.');
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['type'] ?? null)) {
                throw new LogicException('A catalogue row has no type.');
            }
            $this->assertArrayHasKey('destination_keys', $row, "type {$row['type']}");
            $out[$row['type']] = $row['destination_keys'];
        }

        return $out;
    }
}
