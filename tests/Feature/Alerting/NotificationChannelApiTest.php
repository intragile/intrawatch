<?php

namespace Tests\Feature\Alerting;

use App\Enums\ChannelType;
use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsWorkspaceIsolation;
use Tests\TestCase;

class NotificationChannelApiTest extends TestCase
{
    use AssertsWorkspaceIsolation;
    use RefreshDatabase;

    private const SLACK_URL = 'https://hooks.slack.example/services/T000/B000/TOPSECRETVALUE';

    private Workspace $ws;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ws = Workspace::factory()->create();
        $this->editor = User::factory()->memberOf($this->ws, WorkspaceRole::Editor)->create();
    }

    private function id(mixed $value): int
    {
        $this->assertIsInt($value);

        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function arr(mixed $value): array
    {
        $this->assertIsArray($value);

        return $value;
    }

    private function channel(int $id): NotificationChannel
    {
        return NotificationChannel::query()->findOrFail($id);
    }

    private function str(mixed $value): string
    {
        $this->assertIsString($value);

        return $value;
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/workspaces/{$this->ws->slug}/notification-channels{$path}";
    }

    /**
     * @return array<string, mixed>
     */
    private function telegram(): array
    {
        return [
            'name' => 'Ops chat',
            'type' => 'telegram',
            'config' => ['chat_id' => '-100123456'],
            'secrets' => ['bot_token' => '123456:'.str_repeat('a', 35)],
        ];
    }

    public function test_editor_creates_telegram_channel_and_secrets_stay_hidden(): void
    {
        $response = $this->actingAs($this->editor)->postJson($this->url(), $this->telegram())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ops chat')
            ->assertJsonPath('data.type', 'telegram')
            ->assertJsonPath('data.config.chat_id', '-100123456')
            ->assertJsonPath('data.config.server_url', 'https://api.telegram.org')
            ->assertJsonPath('data.secret_keys', ['bot_token'])
            ->assertJsonPath('data.is_default', false)
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.monitor_count', 0)
            ->assertJsonPath('data.last_success_at', null)
            ->assertJsonMissingPath('data.secrets')
            ->assertJsonStructure(['data' => [
                'id', 'name', 'type', 'config', 'secret_keys', 'templates', 'is_default', 'active', 'monitor_count',
                'last_success_at', 'last_failure_at', 'last_error', 'created_at', 'updated_at',
            ]]);
        $this->assertStringNotContainsString('bot_token":"1234', (string) $response->getContent());
        $this->assertStringNotContainsString(str_repeat('a', 35), (string) $response->getContent());

        $id = $this->id($response->json('data.id'));
        $this->actingAs($this->editor)->getJson($this->url("/{$id}"))->assertOk()->assertJsonPath('data.secret_keys', ['bot_token']);
        $this->actingAs($this->editor)->getJson($this->url())->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonStructure(['meta' => ['next_cursor', 'limit']]);
    }

    public function test_channel_secrets_are_write_only_and_never_audited(): void
    {
        $id = $this->id($this->actingAs($this->editor)->postJson($this->url(), [
            'name' => 'Slack', 'type' => 'slack', 'secrets' => ['webhook_url' => self::SLACK_URL],
        ])->assertCreated()->json('data.id'));

        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['name' => 'Slack 2'])->assertOk();
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), [
            'secrets' => ['webhook_url' => self::SLACK_URL.'2'],
        ])->assertOk()->assertJsonPath('data.secret_keys', ['webhook_url']);

        $body = (string) $this->actingAs($this->editor)->getJson($this->url("/{$id}"))->assertOk()->getContent();
        $this->assertStringNotContainsString('TOPSECRETVALUE', $body);
        $this->assertStringNotContainsString('hooks.slack', $body);

        $stored = $this->channel($id);
        $this->assertSame(self::SLACK_URL.'2', $stored->secrets['webhook_url'] ?? null);
        $this->assertStringNotContainsString('TOPSECRETVALUE', $this->str($stored->getRawOriginal('secrets')));

        $audit = AuditEvent::query()->where('action', 'like', 'notification_channel.%')->get();
        $this->assertCount(3, $audit);
        $json = (string) json_encode($audit->pluck('changes')->all());
        $this->assertStringNotContainsString('TOPSECRETVALUE', $json);
        $this->assertStringNotContainsString('hooks.slack', $json);
        $this->assertStringContainsString('secret_keys', $json);
    }

    public function test_patch_keeps_stored_secrets_and_null_removes_them(): void
    {
        $id = $this->id($this->actingAs($this->editor)->postJson($this->url(), $this->telegram())->assertCreated()->json('data.id'));

        // The required bot_token is not sent, so the stored one must satisfy validation.
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['config' => ['silent' => true]])
            ->assertOk()
            ->assertJsonPath('data.config.silent', true)
            ->assertJsonPath('data.config.chat_id', '-100123456')
            ->assertJsonPath('data.secret_keys', ['bot_token']);

        // Removing a required secret is refused and leaves it in place.
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['secrets' => ['bot_token' => null]])
            ->assertUnprocessable()->assertJsonValidationErrors(['secrets.bot_token']);
        $this->assertSame(['bot_token'], array_keys($this->channel($id)->secrets ?? []));

        // An optional secret can be added and removed again.
        $webhook = $this->id($this->actingAs($this->editor)->postJson($this->url(), [
            'name' => 'Hook', 'type' => 'webhook', 'config' => ['url' => 'https://hooks.example.test/in'],
            'secrets' => ['signing_secret' => str_repeat('s', 20)],
        ])->assertCreated()->assertJsonPath('data.secret_keys', ['signing_secret'])->json('data.id'));
        $this->actingAs($this->editor)->patchJson($this->url("/{$webhook}"), ['secrets' => ['signing_secret' => null]])
            ->assertOk()->assertJsonPath('data.secret_keys', []);
        $this->assertNull($this->channel($webhook)->secrets);
    }

    public function test_type_is_immutable_and_validation_is_per_type(): void
    {
        $id = $this->id($this->actingAs($this->editor)->postJson($this->url(), $this->telegram())->assertCreated()->json('data.id'));

        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['type' => 'slack'])
            ->assertUnprocessable()->assertJsonValidationErrors(['type']);
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['type' => 'telegram', 'name' => 'Same type'])->assertOk();

        $this->actingAs($this->editor)->postJson($this->url(), ['name' => 'x', 'type' => 'nope'])
            ->assertUnprocessable()->assertJsonValidationErrors(['type']);
        $this->actingAs($this->editor)->postJson($this->url(), ['name' => 'x', 'type' => 'slack'])
            ->assertUnprocessable()->assertJsonValidationErrors(['secrets.webhook_url']);
        $this->actingAs($this->editor)->postJson($this->url(), ['name' => 'x', 'type' => 'slack', 'secrets' => ['webhook_url' => 'not a url']])
            ->assertUnprocessable()->assertJsonValidationErrors(['secrets.webhook_url']);
    }

    public function test_viewer_and_read_token_cannot_write(): void
    {
        $viewer = User::factory()->memberOf($this->ws, WorkspaceRole::Viewer)->create();
        $channel = NotificationChannel::factory()->for($this->ws)->create();

        $this->actingAs($viewer)->getJson($this->url())->assertOk();
        $this->actingAs($viewer)->getJson($this->url("/{$channel->id}"))->assertOk();
        $this->actingAs($viewer)->postJson($this->url(), $this->telegram())->assertForbidden();
        $this->actingAs($viewer)->patchJson($this->url("/{$channel->id}"), ['name' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->deleteJson($this->url("/{$channel->id}"))->assertForbidden();
        $this->actingAs($viewer)->postJson($this->url("/{$channel->id}/test"))->assertForbidden();
        $this->actingAs($viewer)->postJson($this->url('/test'), $this->telegram())->assertForbidden();

        $mint = fn (array $abilities): string => $this->str($this->actingAs($this->editor, 'sanctum')
            ->postJson("/api/v1/workspaces/{$this->ws->slug}/tokens", ['name' => 'ci', 'abilities' => $abilities])
            ->assertCreated()->json('token'));

        $read = $mint(['notifications:read']);
        $this->app['auth']->forgetGuards();
        $this->withToken($read)->getJson($this->url())->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($read)->postJson($this->url(), $this->telegram())->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($read)->deleteJson($this->url("/{$channel->id}"))->assertForbidden();

        $monitorsOnly = $mint(['monitors:read']);
        $this->app['auth']->forgetGuards();
        $this->withToken($monitorsOnly)->getJson($this->url())->assertForbidden();

        $write = $mint(['notifications:write']);
        $this->app['auth']->forgetGuards();
        $this->withToken($write)->postJson($this->url(), $this->telegram())->assertCreated();

        // A viewer cannot mint a write token at all.
        $this->actingAs($viewer, 'sanctum')
            ->postJson("/api/v1/workspaces/{$this->ws->slug}/tokens", ['name' => 'v', 'abilities' => ['notifications:write']])
            ->assertUnprocessable();
    }

    public function test_cross_workspace_channel_is_not_found(): void
    {
        [$actor, $own, $foreign] = $this->crossWorkspaceFixture();
        $channel = NotificationChannel::factory()->for($foreign)->create();

        $base = "/api/v1/workspaces/{$own->slug}/notification-channels/{$channel->id}";
        $this->assertCrossWorkspaceNotFound($actor, 'GET', $base);
        $this->assertCrossWorkspaceNotFound($actor, 'PATCH', $base, ['name' => 'x']);
        $this->assertCrossWorkspaceNotFound($actor, 'DELETE', $base);
        $this->assertCrossWorkspaceNotFound($actor, 'POST', $base.'/test');

        $this->assertDatabaseHas('notification_channels', ['id' => $channel->id, 'name' => $channel->name]);
    }

    public function test_unknown_template_variable_is_rejected(): void
    {
        $errors = $this->arr($this->actingAs($this->editor)->postJson($this->url(), $this->telegram() + [
            'templates' => ['title' => 'Hi {{ monitor.name }} {{ nope }}', 'body' => '{{ other }} and {{ message }}'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['templates.title', 'templates.body'])
            ->json('errors'));
        $title = $this->str($this->arr($errors['templates.title'])[0]);
        $this->assertStringContainsString('nope', $title);
        $this->assertStringNotContainsString('monitor.name', $title);
        $this->assertStringContainsString('other', $this->str($this->arr($errors['templates.body'])[0]));

        $this->actingAs($this->editor)->postJson($this->url(), $this->telegram() + [
            'templates' => ['title' => str_repeat('a', 201)],
        ])->assertUnprocessable()->assertJsonValidationErrors(['templates.title']);
        $this->actingAs($this->editor)->postJson($this->url(), $this->telegram() + [
            'templates' => ['body' => str_repeat('a', 4001)],
        ])->assertUnprocessable()->assertJsonValidationErrors(['templates.body']);

        $this->actingAs($this->editor)->postJson($this->url(), $this->telegram() + [
            'templates' => ['title' => '[{{ kind_label }}] {{ monitor.name }}', 'body' => '{{ message }}'],
        ])->assertCreated()->assertJsonPath('data.templates.title', '[{{ kind_label }}] {{ monitor.name }}');
    }

    public function test_default_channels_attach_to_new_monitors_and_apply_to_existing(): void
    {
        $existing = Monitor::factory()->for($this->ws)->count(3)->create();

        $id = $this->id($this->actingAs($this->editor)->postJson($this->url(), $this->telegram() + ['apply_to_existing' => true, 'is_default' => true])
            ->assertCreated()->assertJsonPath('data.monitor_count', 3)->assertJsonPath('data.is_default', true)->json('data.id'));
        foreach ($existing as $monitor) {
            $this->assertSame([$id], $monitor->notificationChannelIds());
        }
        $created = AuditEvent::query()->where('action', 'notification_channel.created')->firstOrFail();
        $this->assertSame([null, 3], $created->changes['applied_to_existing'] ?? null);

        // A second apply attaches nothing new; a monitor of another workspace is untouched.
        $foreign = Monitor::factory()->create();
        $extra = Monitor::factory()->for($this->ws)->create();
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['apply_to_existing' => true])
            ->assertOk()->assertJsonPath('data.monitor_count', 4);
        $this->assertSame([], $foreign->notificationChannelIds());
        $this->assertSame([$id], $extra->notificationChannelIds());
        $updated = AuditEvent::query()->where('action', 'notification_channel.updated')->firstOrFail();
        $this->assertSame([null, 1], $updated->changes['applied_to_existing'] ?? null);

        // New monitors get the active default channels only.
        $inactiveDefault = NotificationChannel::factory()->for($this->ws)->inactive()->create(['is_default' => true]);
        NotificationChannel::factory()->for($this->ws)->create();
        $monitorId = $this->id($this->actingAs($this->editor)->postJson("/api/v1/workspaces/{$this->ws->slug}/monitors", [
            'name' => 'New', 'type' => 'http', 'config' => ['url' => 'https://example.test'],
        ])->assertCreated()->assertJsonPath('data.notification_channel_ids', [$id])->json('data.id'));
        $this->assertNotContains($inactiveDefault->id, Monitor::query()->findOrFail($monitorId)->notificationChannelIds());
    }

    public function test_filters_delete_and_audit(): void
    {
        $a = NotificationChannel::factory()->for($this->ws)->create(['name' => 'Pager Duty']);
        NotificationChannel::factory()->for($this->ws)->telegram()->create(['name' => 'Chat']);
        NotificationChannel::factory()->create();

        $this->actingAs($this->editor)->getJson($this->url('?type=slack'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
        $this->actingAs($this->editor)->getJson($this->url('?q=pager'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
        $this->actingAs($this->editor)->getJson($this->url('?type=bogus'))->assertUnprocessable();

        $monitor = Monitor::factory()->for($this->ws)->create();
        $monitor->notificationChannels()->attach($a->id);
        $this->actingAs($this->editor)->getJson($this->url("/{$a->id}"))->assertJsonPath('data.monitor_count', 1);

        $this->actingAs($this->editor)->deleteJson($this->url("/{$a->id}"))->assertNoContent();
        $this->assertDatabaseMissing('notification_channels', ['id' => $a->id]);
        $this->assertSame([], $monitor->notificationChannelIds());
        $this->assertDatabaseHas('audit_events', ['action' => 'notification_channel.deleted']);
    }

    public function test_channel_type_catalogue(): void
    {
        $response = $this->actingAs($this->editor)->getJson('/api/v1/notification-channel-types')->assertOk();
        $rows = $this->arr($response->json());
        $this->assertCount(count(ChannelType::cases()), $rows);

        $byType = [];
        foreach ($rows as $row) {
            $row = $this->arr($row);
            $byType[$this->str($row['type'])] = $row;
        }
        $telegram = $byType['telegram'];
        $this->assertSame(['bot_token'], $telegram['secret_keys']);
        $this->assertContains('chat_id', $this->arr($telegram['config_keys']));
        $this->assertTrue($telegram['supports_digest']);
        $this->assertSame('https://api.telegram.org', $this->arr($telegram['defaults'])['server_url']);
        $this->assertFalse($byType['webhook']['supports_digest']);
        $this->assertContains('smtp_password', $this->arr($byType['email']['secret_keys']));
        $this->assertContains('headers', $this->arr($byType['webhook']['config_keys']));
        $this->assertNotContains('headers.*', $this->arr($byType['webhook']['config_keys']));

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/notification-channel-types')->assertUnauthorized();
    }

    public function test_rename_and_no_op_patch_do_not_audit_secret_keys(): void
    {
        $id = $this->id($this->actingAs($this->editor)->postJson($this->url(), $this->telegram())->assertCreated()->json('data.id'));
        AuditEvent::query()->delete();

        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), [])->assertOk();
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['name' => 'Ops chat'])->assertOk();
        $this->assertSame(0, AuditEvent::query()->count(), 'a true no-op writes no audit row');

        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['name' => 'Renamed'])->assertOk();
        $event = AuditEvent::query()->firstOrFail();
        $this->assertSame(['name'], array_keys($event->changes ?? []));

        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['secrets' => ['bot_token' => '654321:'.str_repeat('b', 35)]])->assertOk();
        $rotated = AuditEvent::query()->latest('id')->firstOrFail();
        $this->assertSame([['bot_token'], ['bot_token']], $rotated->changes['secret_keys'] ?? null);
    }

    public function test_config_stored_in_another_key_order_is_no_change(): void
    {
        $id = $this->id($this->actingAs($this->editor)->postJson($this->url(), $this->telegram())->assertCreated()->json('data.id'));
        // MySQL returns JSON objects with their keys in its own order.
        $config = DB::table('notification_channels')->where('id', $id)->value('config');
        $this->assertIsString($config);
        $decoded = json_decode($config, true);
        $this->assertIsArray($decoded);
        DB::table('notification_channels')->where('id', $id)->update(['config' => json_encode(array_reverse($decoded, true))]);
        AuditEvent::query()->delete();

        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), [])->assertOk();

        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_apply_to_existing_is_audited_only_when_something_was_attached(): void
    {
        $id = $this->id($this->actingAs($this->editor)->postJson($this->url(), $this->telegram() + ['apply_to_existing' => true])->assertCreated()->json('data.id'));
        $this->assertArrayNotHasKey('applied_to_existing', AuditEvent::query()->firstOrFail()->changes ?? []);

        Monitor::factory()->for($this->ws)->create();
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['apply_to_existing' => true])->assertOk();
        $this->assertSame([null, 1], AuditEvent::query()->latest('id')->firstOrFail()->changes['applied_to_existing'] ?? null);

        AuditEvent::query()->delete();
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['apply_to_existing' => true])->assertOk();
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_apply_to_existing_crosses_the_chunk_boundary(): void
    {
        $rows = [];
        foreach (Monitor::factory()->for($this->ws)->count(501)->create() as $monitor) {
            $rows[] = $monitor->id;
        }
        $this->assertCount(501, $rows);

        $id = $this->id($this->actingAs($this->editor)->postJson($this->url(), $this->telegram() + ['apply_to_existing' => true])
            ->assertCreated()->assertJsonPath('data.monitor_count', 501)->json('data.id'));

        $this->assertSame(501, DB::table('monitor_notification')->where('notification_channel_id', $id)->count());
        $this->assertSame([null, 501], AuditEvent::query()->firstOrFail()->changes['applied_to_existing'] ?? null);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
     */
    public static function channelTypes(): array
    {
        $url = 'https://hooks.example.test/in/abcdef';

        return [
            'email' => ['email', ['to' => ['ops@example.test']], []],
            'webhook' => ['webhook', ['url' => 'https://hooks.example.test/in'], ['signing_secret' => str_repeat('s', 20), 'secret_headers' => ['X-Token' => 'abc']]],
            'slack' => ['slack', [], ['webhook_url' => $url]],
            'discord' => ['discord', [], ['webhook_url' => $url]],
            'teams' => ['teams', [], ['webhook_url' => $url]],
            'mattermost' => ['mattermost', [], ['webhook_url' => $url]],
            'google_chat' => ['google_chat', [], ['webhook_url' => $url]],
            'telegram' => ['telegram', ['chat_id' => '-100123456'], ['bot_token' => '123456:'.str_repeat('a', 35)]],
            'ntfy' => ['ntfy', ['topic' => 'alerts'], ['access_token' => 'tk_secret']],
            'gotify' => ['gotify', ['server_url' => 'https://gotify.example.test'], ['app_token' => 'apptoken']],
            'pushover' => ['pushover', [], ['user_key' => 'userkey', 'app_token' => 'apptoken']],
            'matrix' => ['matrix', ['homeserver_url' => 'https://matrix.example.test', 'room_id' => '!room:example.test'], ['access_token' => 'syt_token']],
            'pagerduty' => ['pagerduty', [], ['routing_key' => str_repeat('a', 32)]],
            'opsgenie' => ['opsgenie', [], ['api_key' => 'apikey']],
            'apprise' => ['apprise', ['server_url' => 'https://apprise.example.test'], ['urls' => 'json://example.test/hook']],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $secrets
     */
    #[DataProvider('channelTypes')]
    public function test_renaming_a_channel_keeps_its_stored_secrets(string $type, array $config, array $secrets): void
    {
        $id = $this->id($this->actingAs($this->editor)->postJson($this->url(), [
            'name' => 'Before', 'type' => $type, 'config' => $config, 'secrets' => $secrets,
        ])->assertCreated()->json('data.id'));
        $before = $this->channel($id)->secrets;

        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['name' => 'After'])
            ->assertOk()->assertJsonPath('data.name', 'After');

        $this->assertSame($before, $this->channel($id)->secrets);
    }
}
