<?php

namespace Tests\Feature\Alerting;

use App\Alerting\Channels\ChannelSender;
use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Channels\Senders\SlackSender;
use App\Alerting\Messages\OutgoingNotification;
use App\Alerting\Transport\DeliveryFailure;
use App\Alerting\Transport\OutboundHttp;
use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Models\Workspace;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Alerting\Senders\SenderTestCase;

class NotificationTestSendTest extends SenderTestCase
{
    use RefreshDatabase;

    private const SECRET_URL = 'https://hooks.slack.example/services/T000/B000/TOPSECRETVALUE';

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
        return "/api/v1/workspaces/{$this->ws->slug}/notification-channels{$path}";
    }

    /**
     * @param  list<Response>  $responses
     */
    private function mockHttp(array $responses): void
    {
        $this->app->instance(OutboundHttp::class, $this->http($responses));
    }

    public function test_test_send_reports_failure_without_secrets(): void
    {
        $channel = NotificationChannel::factory()->for($this->ws)->create(['secrets' => ['webhook_url' => self::SECRET_URL]]);
        $this->mockHttp([new Response(401, [], self::SECRET_URL)]);

        $response = $this->actingAs($this->editor)->postJson($this->url("/{$channel->id}/test"))
            ->assertOk()->assertJsonPath('data.ok', false)->assertJsonPath('data.error', 'HTTP 401');
        $this->assertStringNotContainsString('TOPSECRETVALUE', (string) $response->getContent());
        $this->assertStringNotContainsString('hooks.slack', (string) $response->getContent());
        $this->assertSame(self::SECRET_URL, (string) $this->lastRequest()->getUri());
    }

    public function test_saved_channel_test_succeeds_without_side_effects(): void
    {
        $channel = NotificationChannel::factory()->for($this->ws)->create();
        $this->mockHttp([new Response(200)]);

        $this->actingAs($this->editor)->postJson($this->url("/{$channel->id}/test"))
            ->assertOk()->assertExactJson(['data' => ['ok' => true, 'error' => null]]);

        $this->assertCount(1, $this->history);
        $this->assertSame(0, AuditEvent::query()->count());
        $this->assertDatabaseCount('alert_deliveries', 0);
        $this->assertDatabaseCount('alert_events', 0);
        $fresh = $channel->fresh();
        $this->assertNull($fresh?->last_success_at);
        $this->assertNull($fresh?->last_failure_at);
    }

    public function test_unsaved_channel_is_tested_from_the_body_and_not_persisted(): void
    {
        $this->mockHttp([new Response(200)]);

        $this->actingAs($this->editor)->postJson($this->url('/test'), [
            'name' => 'Draft', 'type' => 'slack', 'secrets' => ['webhook_url' => self::SECRET_URL],
            'templates' => ['title' => 'T {{ kind_label }}', 'body' => 'B {{ monitor.name }}'],
        ])->assertOk()->assertExactJson(['data' => ['ok' => true, 'error' => null]]);

        $this->assertSame(self::SECRET_URL, (string) $this->lastRequest()->getUri());
        $this->assertStringContainsString('T TEST', (string) $this->lastRequest()->getBody());
        $this->assertDatabaseCount('notification_channels', 0);
        $this->assertSame(0, AuditEvent::query()->count());

        $this->actingAs($this->editor)->postJson($this->url('/test'), ['name' => 'Draft', 'type' => 'slack'])
            ->assertUnprocessable()->assertJsonValidationErrors(['secrets.webhook_url']);
    }

    public function test_unexpected_errors_are_reported_generically(): void
    {
        $this->app->bind(SlackSender::class, fn () => new class implements ChannelSender
        {
            public function send(ChannelSpec $channel, OutgoingNotification $n, string $idempotencyKey): void
            {
                throw new \RuntimeException('boom '.($channel->secretString('webhook_url') ?? ''));
            }
        });
        $channel = NotificationChannel::factory()->for($this->ws)->create();

        $response = $this->actingAs($this->editor)->postJson($this->url("/{$channel->id}/test"))
            ->assertOk()->assertJsonPath('data.ok', false)->assertJsonPath('data.error', 'Unexpected error');
        $this->assertStringNotContainsString('boom', (string) $response->getContent());
    }

    public function test_delivery_failure_detail_is_redacted(): void
    {
        $this->app->bind(SlackSender::class, fn () => new class implements ChannelSender
        {
            public function send(ChannelSpec $channel, OutgoingNotification $n, string $idempotencyKey): void
            {
                throw new DeliveryFailure('HTTP 400', detail: 'bad request to '.($channel->secretString('webhook_url') ?? ''));
            }
        });
        $channel = NotificationChannel::factory()->for($this->ws)->create(['secrets' => ['webhook_url' => self::SECRET_URL]]);

        $response = $this->actingAs($this->editor)->postJson($this->url("/{$channel->id}/test"))->assertOk()->assertJsonPath('data.ok', false);
        $this->assertStringNotContainsString('TOPSECRETVALUE', (string) $response->getContent());
        $error = $response->json('data.error');
        $this->assertIsString($error);
        $this->assertStringContainsString('HTTP 400', $error);
    }

    public function test_test_send_is_rate_limited_per_user(): void
    {
        $channel = NotificationChannel::factory()->for($this->ws)->create();
        $this->mockHttp(array_fill(0, 12, new Response(200)));

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($this->editor)->postJson($this->url("/{$channel->id}/test"))->assertOk();
        }
        $this->actingAs($this->editor)->postJson($this->url("/{$channel->id}/test"))
            ->assertStatus(429)->assertHeader('Content-Type', 'application/problem+json');

        $other = User::factory()->memberOf($this->ws, WorkspaceRole::Editor)->create();
        $this->actingAs($other)->postJson($this->url("/{$channel->id}/test"))->assertOk();
    }

    public function test_editors_cannot_test_send_through_the_instance_mailer_unsaved(): void
    {
        config(['mail.default' => 'array', 'mail.from.address' => 'app@example.test']);
        $transport = Mail::mailer('array')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $body = ['name' => 'Draft', 'type' => 'email', 'config' => ['to' => ['anyone@example.test']]];

        $this->actingAs($this->editor)->postJson($this->url('/test'), $body)->assertForbidden();
        $this->actingAs($this->editor)->postJson($this->url('/test'), array_replace_recursive($body, ['config' => ['smtp' => null]]))->assertForbidden();
        // An empty smtp object has no host, so the sender would fall back to the instance mailer.
        $this->actingAs($this->editor)->postJson($this->url('/test'), ['name' => 'Draft', 'type' => 'email', 'config' => ['to' => ['anyone@example.test'], 'smtp' => (object) []]])->assertForbidden();
        $this->assertCount(0, $transport->messages(), 'nothing was sent with the organisation sender');

        $admin = User::factory()->memberOf($this->ws, WorkspaceRole::Admin)->create();
        $this->actingAs($admin)->postJson($this->url('/test'), $body)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertCount(1, $transport->messages());

        // A saved channel was set up by an admin, so an editor may test it.
        $saved = NotificationChannel::factory()->for($this->ws)->email()->create();
        $this->actingAs($this->editor)->postJson($this->url("/{$saved->id}/test"))->assertOk()->assertJsonPath('data.ok', true);
    }

    public function test_test_send_is_rate_limited_per_workspace(): void
    {
        $channel = NotificationChannel::factory()->for($this->ws)->create();
        $this->mockHttp(array_fill(0, 32, new Response(200)));

        for ($user = 0; $user < 3; $user++) {
            $editor = User::factory()->memberOf($this->ws, WorkspaceRole::Editor)->create();
            for ($i = 0; $i < 10; $i++) {
                $this->actingAs($editor)->postJson($this->url("/{$channel->id}/test"))->assertOk();
            }
        }

        $fresh = User::factory()->memberOf($this->ws, WorkspaceRole::Editor)->create();
        $this->actingAs($fresh)->postJson($this->url("/{$channel->id}/test"))
            ->assertStatus(429)->assertHeader('Content-Type', 'application/problem+json')->assertHeader('Retry-After');
        $this->actingAs($fresh)->postJson($this->url('/test'), ['name' => 'Draft', 'type' => 'slack', 'secrets' => ['webhook_url' => self::SECRET_URL]])
            ->assertStatus(429);

        // Another workspace has a budget of its own.
        $other = Workspace::factory()->create();
        $outsider = User::factory()->memberOf($other, WorkspaceRole::Editor)->create();
        $foreign = NotificationChannel::factory()->for($other)->create();
        $this->actingAs($outsider)->postJson("/api/v1/workspaces/{$other->slug}/notification-channels/{$foreign->id}/test")->assertOk();
    }
}
