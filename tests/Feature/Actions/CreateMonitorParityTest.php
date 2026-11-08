<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\Monitors\CreateMonitor;
use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CreateMonitorParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'no type' => [['name' => 'X']],
            'unknown type' => [['name' => 'X', 'type' => 'redis']],
            'no name' => [['type' => 'http', 'config' => ['url' => 'https://example.com']]],
            'credentials in url' => [['name' => 'X', 'type' => 'http', 'config' => ['url' => 'https://u:p@example.com']]],
            'credential header' => [['name' => 'X', 'type' => 'http', 'config' => ['url' => 'https://example.com', 'headers' => ['Authorization' => 'x']]]],
            'interval too short' => [['name' => 'X', 'type' => 'http', 'interval_seconds' => 10, 'config' => ['url' => 'https://example.com']]],
            'timeout over ratio' => [['name' => 'X', 'type' => 'tcp', 'interval_seconds' => 20, 'timeout_seconds' => 17, 'config' => ['host' => 'example.com', 'port' => 22]]],
            'ping cadence' => [['name' => 'X', 'type' => 'ping', 'timeout_seconds' => 2, 'config' => ['host' => 'example.com', 'count' => 4]]],
            'inverted push' => [['name' => 'X', 'type' => 'push', 'inverted' => true]],
            'bad jsonpath' => [['name' => 'X', 'type' => 'http', 'config' => ['url' => 'https://example.com', 'json_query' => ['path' => '$.a', 'operator' => 'like']]]],
            'basic without password' => [['name' => 'X', 'type' => 'http', 'config' => ['url' => 'https://example.com', 'auth' => ['type' => 'basic', 'username' => 'u']]]],
            'foreign tag and channel' => [['name' => 'X', 'type' => 'group', 'tags' => [['id' => 999999]], 'notification_channel_ids' => [999999]]],
            'quorum without count' => [['name' => 'X', 'type' => 'group', 'config' => ['mode' => 'quorum']]],
            'blank name' => [['name' => '   ', 'type' => 'group']],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidPayloads')]
    public function test_create_action_matches_api_validation(array $payload): void
    {
        $ws = Workspace::factory()->create();
        $user = User::factory()->memberOf($ws, WorkspaceRole::Editor)->create();
        $this->actingAs($user);

        $response = $this->postJson("/api/v1/workspaces/{$ws->slug}/monitors", $payload);
        $response->assertStatus(422);

        try {
            app(CreateMonitor::class)->validate($ws, $payload);
            $this->fail('Validation should have failed.');
        } catch (ValidationException $e) {
            $apiErrors = $response->json('errors');
            $this->assertIsArray($apiErrors);
            $this->assertSame(array_keys($apiErrors), array_keys($e->errors()));
            $this->assertEquals($apiErrors, $e->errors());
        }
    }

    public function test_validation_without_a_resolved_workspace_scopes_references_to_the_given_one(): void
    {
        $ws = Workspace::factory()->create();
        $other = Workspace::factory()->create();
        $foreign = Tag::factory()->for($other)->create();
        $own = Tag::factory()->for($ws)->create();
        $action = app(CreateMonitor::class);

        try {
            $action->validate($ws, ['name' => 'G', 'type' => 'group', 'tags' => [['id' => $foreign->id]]]);
            $this->fail('A tag of another workspace must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tags.0.id', $e->errors());
        }

        $validated = $action->validate($ws, ['name' => 'G', 'type' => 'group', 'tags' => [['id' => $own->id, 'value' => '']]]);
        $this->assertSame([$own->id => ['value' => null]], $validated['tags']);
    }

    public function test_valid_payload_round_trips_through_handle(): void
    {
        $ws = Workspace::factory()->create();
        $default = NotificationChannel::factory()->for($ws)->create(['is_default' => true, 'active' => true]);
        $action = app(CreateMonitor::class);

        $validated = $action->validate($ws, ['name' => ' Site ', 'type' => 'http', 'config' => ['url' => 'https://example.com']]);
        $monitor = $action->handle($ws, $validated, null);

        $this->assertSame('Site', $monitor->name);
        $this->assertSame(60, $monitor->interval_seconds);
        $this->assertSame([$default->id], $monitor->notificationChannelIds(), 'absent channel ids fall back to the defaults');
        $this->assertDatabaseHas('monitor_states', ['monitor_id' => $monitor->id]);
        $audit = AuditEvent::where('action', 'monitor.created')->sole();
        $this->assertSame('system', $audit->actor_type);
        $this->assertSame([[], [$default->id]], $audit->changes['notification_channel_ids'] ?? null);

        $none = $action->handle($ws, $action->validate($ws, ['name' => 'Quiet', 'type' => 'group', 'notification_channel_ids' => []]), null);
        $this->assertSame([], $none->notificationChannelIds(), 'an explicit empty list binds nothing');
    }

    public function test_handle_keeps_a_supplied_push_token_and_runs_the_callback_in_the_transaction(): void
    {
        $ws = Workspace::factory()->create();
        $action = app(CreateMonitor::class);
        $validated = $action->validate($ws, ['name' => 'P', 'type' => 'push']);
        $validated['push_token'] = 'kumaToken123';

        $seen = null;
        $monitor = $action->handle($ws, $validated, null, function (Monitor $m) use (&$seen): void {
            $seen = $m->exists;
        });

        $this->assertSame('kumaToken123', $monitor->fresh()?->push_token);
        $this->assertTrue($seen);

        $generated = $action->handle($ws, $action->validate($ws, ['name' => 'P2', 'type' => 'push']), null);
        $this->assertSame(48, strlen((string) $generated->push_token));
    }

    public function test_a_failing_callback_rolls_the_monitor_back(): void
    {
        $ws = Workspace::factory()->create();
        $action = app(CreateMonitor::class);

        try {
            $action->handle($ws, $action->validate($ws, ['name' => 'G', 'type' => 'group']), null, function (): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('The callback exception must propagate.');
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, Monitor::count());
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_handle_refuses_an_invalid_parent_like_the_api(): void
    {
        $ws = Workspace::factory()->create();
        $action = app(CreateMonitor::class);
        $notAGroup = Monitor::factory()->for($ws)->create();

        $this->expectException(ValidationException::class);
        $action->handle($ws, $action->validate($ws, ['name' => 'C', 'type' => 'group', 'parent_id' => $notAGroup->id]), null);
    }
}
