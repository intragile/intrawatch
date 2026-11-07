<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\Notifications\CreateNotificationChannel;
use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CreateNotificationChannelParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'bad webhook' => [['name' => 'W', 'type' => 'webhook', 'config' => ['url' => 'ftp://x', 'method' => 'GET', 'headers' => ['Host' => 'x']]]],
            'missing secret' => [['name' => 'S', 'type' => 'slack']],
            'unknown type' => [['name' => 'U', 'type' => 'carrier-pigeon']],
            'no name' => [['type' => 'teams', 'secrets' => ['webhook_url' => 'https://x.example.com']]],
            'email without recipients' => [['name' => 'E', 'type' => 'email', 'config' => ['smtp' => ['port' => 70000]]]],
            'unknown template variable' => [['name' => 'T', 'type' => 'teams', 'secrets' => ['webhook_url' => 'https://x.example.com'], 'templates' => ['title' => '{{ nope }}']]],
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

        $response = $this->postJson("/api/v1/workspaces/{$ws->slug}/notification-channels", $payload);
        $response->assertStatus(422);

        try {
            app(CreateNotificationChannel::class)->validate($ws, $payload);
            $this->fail('Validation should have failed.');
        } catch (ValidationException $e) {
            $apiErrors = $response->json('errors');
            $this->assertIsArray($apiErrors);
            $this->assertSame(array_keys($apiErrors), array_keys($e->errors()));
            $this->assertEquals($apiErrors, $e->errors());
        }
    }

    public function test_valid_payload_round_trips_through_handle(): void
    {
        $ws = Workspace::factory()->create();
        $action = app(CreateNotificationChannel::class);

        $validated = $action->validate($ws, ['name' => ' Hook ', 'type' => 'slack', 'secrets' => ['webhook_url' => 'https://hooks.slack.com/x'], 'templates' => ['title' => '']]);
        $channel = $action->handle($ws, $validated, null);

        $this->assertSame('Hook', $channel->name);
        $this->assertTrue($channel->active);
        $this->assertFalse($channel->is_default);
        $this->assertNull($channel->templates);
        $audit = AuditEvent::where('action', 'notification_channel.created')->sole();
        $this->assertSame($ws->id, $audit->workspace_id);
        $this->assertSame('system', $audit->actor_type);
    }
}
