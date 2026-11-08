<?php

namespace Tests\Feature\Monitoring;

use App\Enums\MonitorStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\PurgeMonitorData;
use App\Models\AuditEvent;
use App\Models\Monitor;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsWorkspaceIsolation;
use Tests\TestCase;

class MonitorApiTest extends TestCase
{
    use AssertsWorkspaceIsolation;
    use RefreshDatabase;

    private Workspace $ws;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ws = Workspace::factory()->create(['timezone' => 'Europe/Budapest']);
        $this->editor = User::factory()->memberOf($this->ws, WorkspaceRole::Editor)->create();
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/workspaces/{$this->ws->slug}/monitors{$path}";
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function http(array $extra = []): array
    {
        return $extra + ['name' => 'Site', 'type' => 'http', 'config' => ['url' => 'https://example.test']];
    }

    public function test_editor_creates_http_monitor_with_defaults(): void
    {
        $this->actingAs($this->editor)->postJson($this->url(), $this->http())
            ->assertCreated()
            ->assertJsonPath('data.interval_seconds', 60)
            ->assertJsonPath('data.timeout_seconds', 10)
            ->assertJsonPath('data.retries', 1)
            ->assertJsonPath('data.retry_interval_seconds', 60)
            ->assertJsonPath('data.config.accepted_status', ['200-299'])
            ->assertJsonPath('data.config.method', 'GET')
            ->assertJsonPath('data.state.status', 'pending')
            ->assertJsonPath('data.push_url', null)
            ->assertJsonPath('data.secret_keys', []);

        $this->assertDatabaseHas('audit_events', ['action' => 'monitor.created']);
    }

    public function test_viewer_cannot_write_and_token_needs_ability(): void
    {
        $viewer = User::factory()->memberOf($this->ws, WorkspaceRole::Viewer)->create();
        $this->actingAs($viewer)->postJson($this->url(), $this->http())->assertForbidden();
        $this->actingAs($viewer)->getJson($this->url())->assertOk();

        $token = $this->editor->createToken('t', ['monitors:read']);
        $token->accessToken->forceFill(['workspace_id' => $this->ws->id])->save();

        $this->app['auth']->forgetGuards();
        $this->withToken($token->plainTextToken)->postJson($this->url(), $this->http())->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($token->plainTextToken)->getJson($this->url())->assertOk();
    }

    public function test_secrets_are_write_only_and_never_audited(): void
    {
        $id = $this->actingAs($this->editor)->postJson($this->url(), $this->http([
            'secrets' => ['bearer_token' => 'tok-123', 'secret_headers' => ['X-Key' => 'hdr-456']],
            'config' => ['url' => 'https://example.test', 'auth' => ['type' => 'bearer']],
        ]))->assertCreated()
            ->assertJsonPath('data.secret_keys', ['bearer_token', 'secret_headers'])
            ->json('data.id');
        $this->assertIsInt($id);

        $body = $this->actingAs($this->editor)->getJson($this->url("/{$id}"))
            ->assertOk()->assertJsonPath('data.secret_keys', ['bearer_token', 'secret_headers'])->getContent();
        $this->assertStringNotContainsString('tok-123', (string) $body);
        $this->assertStringNotContainsString('hdr-456', (string) $body);

        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), [
            'secrets' => ['bearer_token' => null],
            'config' => ['auth' => ['type' => 'none']],
        ])->assertOk()->assertJsonPath('data.secret_keys', ['secret_headers']);

        $monitor = Monitor::findOrFail($id);
        $this->assertSame(['secret_headers' => ['X-Key' => 'hdr-456']], $monitor->secrets);

        foreach (AuditEvent::all() as $event) {
            $json = json_encode($event->toArray());
            $this->assertStringNotContainsString('tok-123', (string) $json);
            $this->assertStringNotContainsString('hdr-456', (string) $json);
        }
        $updated = AuditEvent::where('action', 'monitor.updated')->firstOrFail();
        $this->assertSame([['bearer_token', 'secret_headers'], ['secret_headers']], $updated->changes['secret_keys'] ?? null);
    }

    public function test_bearer_token_is_required_on_create_but_not_on_update(): void
    {
        $this->actingAs($this->editor)->postJson($this->url(), $this->http([
            'config' => ['url' => 'https://example.test', 'auth' => ['type' => 'bearer']],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['secrets.bearer_token']);
    }

    public function test_interval_and_timeout_limits(): void
    {
        $this->actingAs($this->editor)->postJson($this->url(), $this->http(['interval_seconds' => 19]))
            ->assertUnprocessable()->assertJsonValidationErrors(['interval_seconds']);
        $this->actingAs($this->editor)->postJson($this->url(), [
            'name' => 'D', 'type' => 'domain', 'interval_seconds' => 600, 'config' => ['domain' => 'example.test'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['interval_seconds']);
        $this->actingAs($this->editor)->postJson($this->url(), $this->http(['interval_seconds' => 60, 'timeout_seconds' => 50]))
            ->assertUnprocessable()->assertJsonValidationErrors(['timeout_seconds']);
        $this->actingAs($this->editor)->postJson($this->url(), $this->http(['interval_seconds' => 60, 'timeout_seconds' => 48]))
            ->assertCreated();
    }

    public function test_ping_count_must_fit_inside_the_timeout(): void
    {
        $ping = fn (int $count, int $timeout): array => ['name' => 'P', 'type' => 'ping', 'config' => ['host' => '10.0.0.1', 'count' => $count], 'timeout_seconds' => $timeout];

        $this->actingAs($this->editor)->postJson($this->url(), $ping(5, 4))
            ->assertUnprocessable()->assertJsonValidationErrors(['timeout_seconds']);
        $this->actingAs($this->editor)->postJson($this->url(), $ping(5, 5))->assertCreated();
        $this->actingAs($this->editor)->postJson($this->url(), $ping(1, 1))->assertCreated();
    }

    public function test_cron_timezone_defaults_to_workspace_timezone(): void
    {
        $this->actingAs($this->editor)->postJson($this->url(), [
            'name' => 'Backup', 'type' => 'cron', 'config' => ['schedule' => '*/5 * * * *'],
        ])->assertCreated()
            ->assertJsonPath('data.config.timezone', 'Europe/Budapest')
            ->assertJsonPath('data.push_url', fn (string $url) => str_starts_with($url, rtrim(config()->string('app.url'), '/').'/api/v1/push/') && strlen($url) > 60);
        $this->assertSame(48, strlen((string) Monitor::firstOrFail()->push_token));
    }

    public function test_type_is_immutable_and_config_merges(): void
    {
        $m = Monitor::factory()->for($this->ws)->http('https://a.test')->create();

        $this->actingAs($this->editor)->patchJson($this->url("/{$m->id}"), ['type' => 'tcp'])
            ->assertUnprocessable()->assertJsonValidationErrors(['type']);

        $this->actingAs($this->editor)->patchJson($this->url("/{$m->id}"), ['config' => ['method' => 'HEAD']])
            ->assertOk()
            ->assertJsonPath('data.config.method', 'HEAD')
            ->assertJsonPath('data.config.url', 'https://a.test');
    }

    public function test_group_parent_rules(): void
    {
        $plain = Monitor::factory()->for($this->ws)->http()->create();
        $this->actingAs($this->editor)->postJson($this->url(), $this->http(['parent_id' => $plain->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);

        $a = Monitor::factory()->for($this->ws)->group()->create();
        $b = Monitor::factory()->for($this->ws)->group()->create(['parent_id' => $a->id]);
        $this->actingAs($this->editor)->patchJson($this->url("/{$a->id}"), ['parent_id' => $b->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);
        $this->actingAs($this->editor)->patchJson($this->url("/{$a->id}"), ['parent_id' => $a->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);

        $parent = null;
        for ($i = 0; $i < 5; $i++) {
            $parent = Monitor::factory()->for($this->ws)->group()->create(['parent_id' => $parent?->id]);
        }
        $this->actingAs($this->editor)->postJson($this->url(), $this->http(['parent_id' => $parent->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);

        $foreign = Monitor::factory()->group()->create();
        $this->actingAs($this->editor)->postJson($this->url(), $this->http(['parent_id' => $foreign->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);

        $this->actingAs($this->editor)->postJson($this->url(), $this->http(['parent_id' => $a->id]))
            ->assertCreated()->assertJsonPath('data.parent_id', $a->id);
    }

    public function test_moving_a_subtree_counts_its_height(): void
    {
        $root = null;
        for ($i = 0; $i < 4; $i++) {
            $root = Monitor::factory()->for($this->ws)->group()->create(['parent_id' => $root?->id]);
        }
        $top = Monitor::factory()->for($this->ws)->group()->create();
        $mid = Monitor::factory()->for($this->ws)->group()->create(['parent_id' => $top->id]);

        // root chain is 4 deep; moving a 2-deep subtree below it gives 6.
        $this->actingAs($this->editor)->patchJson($this->url("/{$top->id}"), ['parent_id' => $root->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);
        $this->assertSame($top->id, $mid->fresh()?->parent_id);
    }

    public function test_tags_are_assigned_and_replaced(): void
    {
        $t1 = Tag::factory()->for($this->ws)->create();
        $t2 = Tag::factory()->for($this->ws)->create();
        $foreign = Tag::factory()->create();

        $id = $this->actingAs($this->editor)->postJson($this->url(), $this->http(['tags' => [['id' => $t1->id, 'value' => 'prod']]]))
            ->assertCreated()
            ->assertJsonPath('data.tags.0.id', $t1->id)
            ->assertJsonPath('data.tags.0.value', 'prod')
            ->json('data.id');
        $this->assertIsInt($id);

        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['tags' => [['id' => $t2->id]]])
            ->assertOk()->assertJsonCount(1, 'data.tags')->assertJsonPath('data.tags.0.id', $t2->id)->assertJsonPath('data.tags.0.value', null);

        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['tags' => [['id' => $foreign->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['tags.0.id']);
    }

    public function test_monitor_endpoints_are_workspace_isolated(): void
    {
        [$actor, $own, $foreign] = $this->crossWorkspaceFixture();
        $m = Monitor::factory()->for($foreign)->create();
        foreach (['GET', 'PATCH', 'DELETE'] as $verb) {
            $this->assertCrossWorkspaceNotFound($actor, $verb, "/api/v1/workspaces/{$own->slug}/monitors/{$m->id}");
        }
        foreach (['pause', 'resume', 'check', 'push-token'] as $action) {
            $this->assertCrossWorkspaceNotFound($actor, 'POST', "/api/v1/workspaces/{$own->slug}/monitors/{$m->id}/{$action}");
        }
    }

    public function test_list_filters_by_status_tag_and_query(): void
    {
        $tag = Tag::factory()->for($this->ws)->create();
        $web = Monitor::factory()->for($this->ws)->http()->create(['name' => 'Web 100%_shop']);
        $web->tags()->attach($tag);
        $db = Monitor::factory()->for($this->ws)->tcp()->create(['name' => 'Database']);
        $db->state->update(['status' => MonitorStatus::Down]);
        $group = Monitor::factory()->for($this->ws)->group()->create(['name' => 'Group']);
        $child = Monitor::factory()->for($this->ws)->http()->create(['name' => 'Child', 'parent_id' => $group->id, 'active' => false]);
        Monitor::factory()->create(['name' => 'Web foreign']);

        $names = function (string $qs): array {
            $data = $this->actingAs($this->editor)->getJson($this->url("?{$qs}"))->assertOk()->json('data');
            $this->assertIsArray($data);

            return array_column($data, 'name');
        };

        $this->assertSame(['Web 100%_shop', 'Database', 'Group', 'Child'], $names(''));
        $this->assertSame(['Database'], $names('status=down'));
        $this->assertSame(['Web 100%_shop'], $names("tag={$tag->id}"));
        $this->assertSame(['Web 100%_shop'], $names('q=web'));
        $this->assertSame(['Web 100%_shop'], $names('q='.urlencode('100%_')));
        $this->assertSame(['Web 100%_shop'], $names('q='.urlencode('%')));
        $this->assertSame(['Database'], $names('type=tcp'));
        $this->assertSame(['Web 100%_shop', 'Database', 'Group'], $names('parent_id=null'));
        $this->assertSame(['Child'], $names("parent_id={$group->id}"));
        $this->assertSame(['Child'], $names('active=false'));
        $this->assertSame($child->id, $this->actingAs($this->editor)->getJson($this->url('?active=false'))->json('data.0.id'));
    }

    public function test_list_rejects_invalid_filters(): void
    {
        $this->actingAs($this->editor)->getJson($this->url('?status=bogus'))->assertUnprocessable();
        $this->actingAs($this->editor)->getJson($this->url('?type=bogus'))->assertUnprocessable();
    }

    public function test_pause_resume_and_check(): void
    {
        $m = Monitor::factory()->for($this->ws)->http()->create();

        $this->actingAs($this->editor)->postJson($this->url("/{$m->id}/pause"))
            ->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.state.status', 'paused');
        $this->actingAs($this->editor)->postJson($this->url("/{$m->id}/resume"))
            ->assertOk()->assertJsonPath('data.active', true)->assertJsonPath('data.state.status', 'pending');

        $this->assertNull($m->fresh()?->check_requested_at);
        $this->actingAs($this->editor)->postJson($this->url("/{$m->id}/check"))->assertStatus(202);
        $this->assertNotNull($m->fresh()?->check_requested_at);

        foreach (['push', 'cron'] as $type) {
            $p = Monitor::factory()->for($this->ws)->{$type}()->create();
            $this->actingAs($this->editor)->postJson($this->url("/{$p->id}/check"))->assertUnprocessable();
        }

        foreach (['monitor.paused', 'monitor.resumed', 'monitor.check_requested'] as $action) {
            $this->assertDatabaseHas('audit_events', ['action' => $action]);
        }
    }

    public function test_patch_active_syncs_state(): void
    {
        $m = Monitor::factory()->for($this->ws)->http()->create();
        $this->actingAs($this->editor)->patchJson($this->url("/{$m->id}"), ['active' => false])
            ->assertOk()->assertJsonPath('data.state.status', 'paused');
    }

    public function test_push_url_is_shown_only_to_callers_who_may_manage_monitors(): void
    {
        $push = Monitor::factory()->for($this->ws)->push()->create();
        $url = rtrim(config()->string('app.url'), '/')."/api/v1/push/{$push->push_token}";
        $viewer = User::factory()->memberOf($this->ws, WorkspaceRole::Viewer)->create();

        $this->actingAs($viewer)->getJson($this->url("/{$push->id}"))->assertOk()
            ->assertJsonPath('data.push_url', null)
            ->assertJsonPath('data.has_push_token', true);
        $this->actingAs($viewer)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.0.push_url', null)
            ->assertJsonPath('data.0.has_push_token', true);
        $this->actingAs($this->editor)->getJson($this->url("/{$push->id}"))->assertOk()
            ->assertJsonPath('data.push_url', $url);
        $this->actingAs($this->editor)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.0.push_url', $url);

        $read = $this->editor->createToken('read', ['monitors:read']);
        $read->accessToken->forceFill(['workspace_id' => $this->ws->id])->save();
        $write = $this->editor->createToken('write', ['monitors:read', 'monitors:write']);
        $write->accessToken->forceFill(['workspace_id' => $this->ws->id])->save();

        $this->app['auth']->forgetGuards();
        $this->withToken($read->plainTextToken)->getJson($this->url("/{$push->id}"))->assertOk()
            ->assertJsonPath('data.push_url', null)
            ->assertJsonPath('data.has_push_token', true);
        $this->app['auth']->forgetGuards();
        $this->withToken($write->plainTextToken)->getJson($this->url("/{$push->id}"))->assertOk()
            ->assertJsonPath('data.push_url', $url);

        $http = Monitor::factory()->for($this->ws)->http()->create();
        $this->app['auth']->forgetGuards();
        $this->withToken($write->plainTextToken)->getJson($this->url("/{$http->id}"))->assertOk()
            ->assertJsonPath('data.push_url', null)
            ->assertJsonPath('data.has_push_token', false);
    }

    public function test_credential_headers_are_refused_in_plain_headers(): void
    {
        foreach (['Authorization', 'cookie', 'Proxy-Authorization', 'X-Api-Key'] as $name) {
            $this->actingAs($this->editor)->postJson($this->url(), $this->http([
                'config' => ['url' => 'https://example.test', 'headers' => [$name => 'value']],
            ]))->assertUnprocessable()->assertJsonValidationErrors('config.headers');
        }

        $this->actingAs($this->editor)->postJson($this->url(), $this->http([
            'config' => ['url' => 'https://example.test', 'headers' => ['Accept' => 'text/html']],
            'secrets' => ['secret_headers' => ['Authorization' => 'Bearer x']],
        ]))->assertCreated();
    }

    public function test_push_token_rotation(): void
    {
        $push = Monitor::factory()->for($this->ws)->push()->create();
        $old = $push->push_token;

        $response = $this->actingAs($this->editor)->postJson($this->url("/{$push->id}/push-token"))->assertOk();
        $new = $push->fresh()?->push_token;
        $this->assertNotSame($old, $new);
        $this->assertSame(rtrim(config()->string('app.url'), '/')."/api/v1/push/{$new}", $response->json('push_url'));

        $http = Monitor::factory()->for($this->ws)->http()->create();
        $this->actingAs($this->editor)->postJson($this->url("/{$http->id}/push-token"))->assertUnprocessable();

        $event = AuditEvent::where('action', 'monitor.push_token_rotated')->firstOrFail();
        $this->assertStringNotContainsString((string) $new, (string) json_encode($event->toArray()));
    }

    public function test_delete_dispatches_purge_and_orphans_children(): void
    {
        Queue::fake();
        $group = Monitor::factory()->for($this->ws)->group()->create();
        $child = Monitor::factory()->for($this->ws)->http()->create(['parent_id' => $group->id]);
        $createdAt = $child->updated_at ?? now();
        $this->travel(10)->seconds();

        $this->actingAs($this->editor)->deleteJson($this->url("/{$group->id}"))->assertNoContent();

        $this->assertDatabaseMissing('monitors', ['id' => $group->id]);
        $this->assertNull($child->fresh()?->parent_id);
        // The bumped timestamp is what tells the check worker that the parent changed.
        $this->assertTrue($child->fresh()?->updated_at?->gt($createdAt));
        Queue::assertPushed(PurgeMonitorData::class, fn (PurgeMonitorData $job) => $job->monitorId === $group->id);
        $this->assertDatabaseHas('audit_events', ['action' => 'monitor.deleted']);
    }

    public function test_monitor_types_catalogue(): void
    {
        $response = $this->actingAs($this->editor)->getJson('/api/v1/monitor-types')->assertOk();
        $response->assertJsonCount(9)
            ->assertJsonPath('0.type', 'http')
            ->assertJsonPath('0.default_interval_seconds', 60)
            ->assertJsonPath('0.supports_inverted', true)
            ->assertJsonPath('0.defaults.accepted_status', ['200-299'])
            ->assertJsonPath('5.type', 'domain')
            ->assertJsonPath('5.min_interval_seconds', 3600)
            ->assertJsonPath('8.type', 'group')
            ->assertJsonPath('8.scheduled', false);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/monitor-types')->assertUnauthorized();
    }

    private const STORED_SECRETS = ['bearer_token' => 'tok-123', 'secret_headers' => ['X-Key' => 'hdr-456'], 'proxy_password' => 'proxy-pw'];

    private function securedMonitor(): Monitor
    {
        $id = $this->actingAs($this->editor)->postJson($this->url(), $this->http([
            'config' => [
                'url' => 'https://example.test/health',
                'auth' => ['type' => 'bearer'],
                'proxy' => ['url' => 'http://proxy.example.test:3128', 'username' => 'ops'],
            ],
            'secrets' => self::STORED_SECRETS,
        ]))->assertCreated()->json('data.id');
        $this->assertIsInt($id);

        return Monitor::findOrFail($id);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function monitorDestinationChanges(): array
    {
        return [
            'url' => [['url' => 'https://attacker.example.test/health']],
            'proxy url' => [['proxy' => ['url' => 'http://attacker.example.test:3128', 'username' => 'ops']]],
            'proxy removed' => [['proxy' => null]],
        ];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('monitorDestinationChanges')]
    public function test_changing_the_destination_requires_the_stored_secrets(array $change): void
    {
        $monitor = $this->securedMonitor();
        $config = $monitor->config;

        $this->actingAs($this->editor)->patchJson($this->url("/{$monitor->id}"), ['config' => $change])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['secrets', 'secrets.bearer_token', 'secrets.secret_headers', 'secrets.proxy_password']);
        $fresh = Monitor::findOrFail($monitor->id);
        $this->assertEquals(self::STORED_SECRETS, $fresh->secrets);
        $this->assertSame($config, $fresh->config);

        $this->actingAs($this->editor)->patchJson($this->url("/{$monitor->id}"), ['config' => $change, 'secrets' => self::STORED_SECRETS])
            ->assertOk();
        $this->assertEquals(self::STORED_SECRETS, Monitor::findOrFail($monitor->id)->secrets);
    }

    public function test_changing_the_destination_may_remove_secrets_with_null(): void
    {
        $monitor = $this->securedMonitor();

        $this->actingAs($this->editor)->patchJson($this->url("/{$monitor->id}"), [
            'config' => ['url' => 'https://elsewhere.example.test', 'auth' => ['type' => 'none'], 'proxy' => null],
            'secrets' => ['bearer_token' => null, 'secret_headers' => null, 'proxy_password' => null],
        ])->assertOk()->assertJsonPath('data.secret_keys', []);
        $this->assertNull(Monitor::findOrFail($monitor->id)->secrets);
    }

    public function test_adding_a_proxy_requires_the_stored_secrets(): void
    {
        $id = $this->actingAs($this->editor)->postJson($this->url(), $this->http([
            'config' => ['url' => 'https://example.test', 'auth' => ['type' => 'bearer']],
            'secrets' => ['bearer_token' => 'tok-123'],
        ]))->assertCreated()->json('data.id');
        $this->assertIsInt($id);

        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['config' => ['proxy' => ['url' => 'http://attacker.example.test:8080']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['secrets', 'secrets.bearer_token']);
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), [
            'config' => ['proxy' => ['url' => 'http://proxy.example.test:8080']], 'secrets' => ['bearer_token' => 'tok-123'],
        ])->assertOk();
    }

    public function test_changing_another_field_keeps_the_stored_secrets(): void
    {
        $monitor = $this->securedMonitor();

        $this->actingAs($this->editor)->patchJson($this->url("/{$monitor->id}"), ['config' => ['method' => 'HEAD'], 'name' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.config.method', 'HEAD');
        // The same destination sent again is no change.
        $this->actingAs($this->editor)->patchJson($this->url("/{$monitor->id}"), ['config' => ['url' => 'https://example.test/health']])
            ->assertOk();

        $this->assertEquals(self::STORED_SECRETS, Monitor::findOrFail($monitor->id)->secrets);
    }
}
