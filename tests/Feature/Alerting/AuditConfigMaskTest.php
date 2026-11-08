<?php

namespace Tests\Feature\Alerting;

use App\Actions\Notifications\CreateNotificationChannel;
use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\Monitor;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit rows are append-only and readable with an `audit:read` token, so they must never hold the sensitive
 * config values (a webhook path, an ntfy topic, an apprise key) that the channel endpoints mask.
 */
class AuditConfigMaskTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_URL = 'https://hooks.example.com:8443/T0/SECRETPATH?token=QSVALUE';

    private Workspace $ws;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ws = Workspace::factory()->create();
        $this->editor = User::factory()->memberOf($this->ws, WorkspaceRole::Editor)->create();
    }

    private function url(string $path): string
    {
        return "/api/v1/workspaces/{$this->ws->slug}{$path}";
    }

    /**
     * @return array<array-key, mixed>
     */
    private function arr(mixed $value): array
    {
        $this->assertIsArray($value);

        return $value;
    }

    /**
     * The `[old, new]` pair recorded for `config`.
     *
     * @return array{0: array<array-key, mixed>|null, 1: array<array-key, mixed>|null}
     */
    private function configPair(AuditEvent $event): array
    {
        $pair = $this->arr($this->arr($event->changes)['config'] ?? null);
        $old = $pair[0] ?? null;
        $new = $pair[1] ?? null;
        $this->assertTrue($old === null || is_array($old));
        $this->assertTrue($new === null || is_array($new));

        return [$old, $new];
    }

    private function dump(AuditEvent $event): string
    {
        return (string) json_encode($event->toArray());
    }

    private function createWebhook(string $url): int
    {
        $id = $this->actingAs($this->editor)->postJson($this->url('/notification-channels'), [
            'name' => 'Hook', 'type' => 'webhook', 'config' => ['url' => $url, 'method' => 'PUT'],
        ])->assertCreated()->json('data.id');
        $this->assertIsInt($id);

        return $id;
    }

    public function test_created_webhook_channel_audit_masks_url(): void
    {
        $this->createWebhook(self::WEBHOOK_URL);

        $event = AuditEvent::where('action', 'notification_channel.created')->sole();
        [$old, $new] = $this->configPair($event);
        $this->assertNull($old);
        $this->assertSame('https://hooks.example.com:8443/***', $new['url'] ?? null);
        $this->assertSame('PUT', $new['method'] ?? null, 'non-sensitive keys stay readable');
        $this->assertStringNotContainsString('SECRETPATH', $this->dump($event));
        $this->assertStringNotContainsString('QSVALUE', $this->dump($event));
    }

    public function test_ntfy_and_apprise_audit_mask_topic_and_config_key(): void
    {
        $this->actingAs($this->editor)->postJson($this->url('/notification-channels'), [
            'name' => 'Ntfy', 'type' => 'ntfy', 'config' => ['server_url' => 'https://ntfy.example.com', 'topic' => 'secret-topic-123'],
        ])->assertCreated();
        $this->actingAs($this->editor)->postJson($this->url('/notification-channels'), [
            'name' => 'Apprise', 'type' => 'apprise', 'config' => ['server_url' => 'https://apprise.example.com', 'config_key' => 'secret-key-456'],
        ])->assertCreated();

        $events = AuditEvent::where('action', 'notification_channel.created')->orderBy('id')->get()->all();
        $this->assertCount(2, $events);
        [$ntfyEvent, $appriseEvent] = $events;

        [, $ntfy] = $this->configPair($ntfyEvent);
        $this->assertSame('***', $ntfy['topic'] ?? null);
        $this->assertSame('https://ntfy.example.com', $ntfy['server_url'] ?? null);
        $this->assertStringNotContainsString('secret-topic-123', $this->dump($ntfyEvent));

        [, $apprise] = $this->configPair($appriseEvent);
        $this->assertSame('***', $apprise['config_key'] ?? null);
        $this->assertSame('https://apprise.example.com', $apprise['server_url'] ?? null);
        $this->assertStringNotContainsString('secret-key-456', $this->dump($appriseEvent));
    }

    public function test_updated_channel_diff_masks_old_and_new_sensitive_values(): void
    {
        $id = $this->createWebhook(self::WEBHOOK_URL);

        $this->actingAs($this->editor)->patchJson($this->url("/notification-channels/{$id}"), [
            'config' => ['url' => 'https://other.example.com/NEWSECRET/path', 'method' => 'POST'],
        ])->assertOk();

        $event = AuditEvent::where('action', 'notification_channel.updated')->sole();
        [$old, $new] = $this->configPair($event);
        $this->assertSame('https://hooks.example.com:8443/***', $old['url'] ?? null);
        $this->assertSame('https://other.example.com/***', $new['url'] ?? null);
        $this->assertSame('PUT', $old['method'] ?? null);
        $this->assertSame('POST', $new['method'] ?? null);
        foreach (['SECRETPATH', 'QSVALUE', 'NEWSECRET'] as $secret) {
            $this->assertStringNotContainsString($secret, $this->dump($event));
        }
    }

    public function test_a_path_only_change_is_still_audited_but_masked(): void
    {
        $id = $this->createWebhook(self::WEBHOOK_URL);

        $this->actingAs($this->editor)->patchJson($this->url("/notification-channels/{$id}"), [
            'config' => ['url' => 'https://hooks.example.com:8443/T0/ROTATED'],
        ])->assertOk();

        $event = AuditEvent::where('action', 'notification_channel.updated')->sole();
        [$old, $new] = $this->configPair($event);
        $this->assertSame('https://hooks.example.com:8443/***', $old['url'] ?? null);
        $this->assertSame('https://hooks.example.com:8443/***', $new['url'] ?? null);
        $this->assertStringNotContainsString('ROTATED', $this->dump($event));
        $this->assertStringNotContainsString('SECRETPATH', $this->dump($event));
    }

    public function test_action_path_masks_like_the_api(): void
    {
        $create = app(CreateNotificationChannel::class);
        $create->handle($this->ws, $create->validate($this->ws, [
            'name' => 'Imported', 'type' => 'webhook', 'config' => ['url' => self::WEBHOOK_URL],
        ]), null);

        $event = AuditEvent::where('action', 'notification_channel.created')->sole();
        [, $new] = $this->configPair($event);
        $this->assertSame('https://hooks.example.com:8443/***', $new['url'] ?? null);
        $this->assertStringNotContainsString('SECRETPATH', $this->dump($event));
    }

    public function test_monitor_audit_contains_no_secret_values(): void
    {
        $secrets = [
            'bearer_token' => 'BEARER-SECRET-1', 'secret_headers' => ['X-Key' => 'HEADER-SECRET-1'], 'proxy_password' => 'PROXY-SECRET-1',
        ];
        $id = $this->actingAs($this->editor)->postJson($this->url('/monitors'), [
            'name' => 'Site', 'type' => 'http',
            'config' => [
                'url' => 'https://example.test/health',
                'auth' => ['type' => 'bearer'],
                'proxy' => ['url' => 'http://proxy.example.test:3128', 'username' => 'ops'],
            ],
            'secrets' => $secrets,
        ])->assertCreated()->json('data.id');
        $this->assertIsInt($id);

        $this->actingAs($this->editor)->patchJson($this->url("/monitors/{$id}"), [
            'config' => ['url' => 'https://example.test/other'],
            'secrets' => ['bearer_token' => 'BEARER-SECRET-2', 'secret_headers' => ['X-Key' => 'HEADER-SECRET-2'], 'proxy_password' => 'PROXY-SECRET-2'],
        ])->assertOk();

        $pushId = $this->actingAs($this->editor)->postJson($this->url('/monitors'), [
            'name' => 'Cron', 'type' => 'push',
        ])->assertCreated()->json('data.id');
        $this->assertIsInt($pushId);
        $firstToken = (string) Monitor::findOrFail($pushId)->push_token;
        $this->actingAs($this->editor)->postJson($this->url("/monitors/{$pushId}/push-token"))->assertOk();
        $this->actingAs($this->editor)->patchJson($this->url("/monitors/{$pushId}"), ['name' => 'Cron job'])->assertOk();
        $secondToken = (string) Monitor::findOrFail($pushId)->push_token;

        $events = AuditEvent::where('action', 'like', 'monitor.%')->get();
        $this->assertGreaterThanOrEqual(4, $events->count());
        $this->assertTrue($events->contains('action', 'monitor.created'));
        $this->assertTrue($events->contains('action', 'monitor.updated'));
        $dump = (string) json_encode($events->toArray());
        foreach (['BEARER-SECRET', 'HEADER-SECRET', 'PROXY-SECRET', $firstToken, $secondToken] as $secret) {
            $this->assertNotSame('', $secret);
            $this->assertStringNotContainsString($secret, $dump);
        }
    }
}
