<?php

namespace Tests\Feature\Alerting;

use App\Enums\AlertKind;
use App\Enums\DeliveryStatus;
use App\Enums\MonitorStatus;
use App\Enums\WorkspaceRole;
use App\Models\AlertDelivery;
use App\Models\AlertEvent;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AssertsWorkspaceIsolation;
use Tests\TestCase;

class AlertApiTest extends TestCase
{
    use AssertsWorkspaceIsolation;
    use RefreshDatabase;

    private Workspace $ws;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ws = Workspace::factory()->create();
        $this->editor = User::factory()->memberOf($this->ws, WorkspaceRole::Editor)->create();
    }

    private function url(string $path = '/alerts', ?Workspace $ws = null): string
    {
        return '/api/v1/workspaces/'.($ws ?? $this->ws)->slug.$path;
    }

    private function str(mixed $value): string
    {
        $this->assertIsString($value);

        return $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function event(Workspace $ws, ?Monitor $monitor, AlertKind $kind, string $at, array $attributes = []): AlertEvent
    {
        return AlertEvent::query()->create([
            'workspace_id' => $ws->id,
            'monitor_id' => $monitor?->id,
            'kind' => $kind,
            'status' => $kind === AlertKind::Down ? MonitorStatus::Down : MonitorStatus::Up,
            'previous_status' => $kind === AlertKind::Down ? MonitorStatus::Up : MonitorStatus::Down,
            'message' => ucfirst($kind->value).' message',
            'created_at' => CarbonImmutable::parse($at),
            ...$attributes,
        ]);
    }

    /**
     * @param  list<int>  $eventIds
     * @param  array<string, mixed>  $attributes
     */
    private function delivery(Workspace $ws, ?NotificationChannel $channel, array $eventIds, array $attributes = []): AlertDelivery
    {
        return AlertDelivery::query()->create([
            'workspace_id' => $ws->id,
            'notification_channel_id' => $channel?->id,
            'alert_event_ids' => $eventIds,
            'status' => DeliveryStatus::Queued,
            ...$attributes,
        ]);
    }

    public function test_lists_alerts_with_deliveries_newest_first(): void
    {
        $monitor = Monitor::factory()->for($this->ws)->create(['name' => 'Shop']);
        $slack = NotificationChannel::factory()->for($this->ws)->create(['name' => 'Ops Slack', 'type' => 'slack']);
        $hook = NotificationChannel::factory()->for($this->ws)->create(['name' => 'Hook', 'type' => 'webhook']);

        $down = $this->event($this->ws, $monitor, AlertKind::Down, '2026-11-01T10:00:00Z', ['message' => 'Connection refused']);
        $up = $this->event($this->ws, $monitor, AlertKind::Up, '2026-11-01T10:05:00Z', ['duration_seconds' => 300]);
        $changed = $this->event($this->ws, $monitor, AlertKind::Changed, '2026-11-01T10:06:00Z', [
            'status' => null, 'previous_status' => null,
            'changes' => [['key' => 'cert.fingerprint', 'old' => 'aa', 'new' => 'bb']],
        ]);
        $orphan = $this->event($this->ws, null, AlertKind::Down, '2026-11-01T10:07:00Z', ['suppressed_by_monitor_id' => 99]);

        // A digest delivery covering two events, a failed retry and one whose channel was deleted.
        $digest = $this->delivery($this->ws, $slack, [$down->id, $up->id], [
            'status' => DeliveryStatus::Sent, 'attempts' => 1, 'sent_at' => CarbonImmutable::parse('2026-11-01T10:05:02Z'),
        ]);
        $failed = $this->delivery($this->ws, $hook, [$down->id], [
            'status' => DeliveryStatus::Failed, 'attempts' => 5, 'last_error' => 'HTTP 503',
        ]);
        $removed = $this->delivery($this->ws, null, [$up->id], ['status' => DeliveryStatus::Failed, 'last_error' => 'Channel removed']);

        DB::enableQueryLog();
        $response = $this->actingAs($this->editor)->getJson($this->url())->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertJsonPath('data.*.id', [$orphan->id, $changed->id, $up->id, $down->id])
            ->assertJsonStructure(['data' => [[
                'id', 'kind', 'monitor', 'status', 'previous_status', 'message', 'duration_seconds', 'changes',
                'suppressed_by_monitor_id', 'created_at', 'deliveries',
            ]], 'meta' => ['next_cursor', 'limit']]);

        $response->assertJsonPath('data.0.monitor', null)
            ->assertJsonPath('data.0.suppressed_by_monitor_id', 99)
            ->assertJsonPath('data.0.deliveries', [])
            ->assertJsonPath('data.1.kind', 'changed')
            ->assertJsonPath('data.1.status', null)
            ->assertJsonPath('data.1.changes', [['key' => 'cert.fingerprint', 'old' => 'aa', 'new' => 'bb']])
            ->assertJsonPath('data.2.duration_seconds', 300)
            ->assertJsonPath('data.2.previous_status', 'down')
            ->assertJsonPath('data.2.deliveries.*.id', [$digest->id, $removed->id])
            ->assertJsonPath('data.2.deliveries.1.channel', null)
            ->assertJsonPath('data.2.deliveries.1.last_error', 'Channel removed');

        $response->assertJsonPath('data.3', [
            'id' => $down->id,
            'kind' => 'down',
            'monitor' => ['id' => $monitor->id, 'name' => 'Shop'],
            'status' => 'down',
            'previous_status' => 'up',
            'message' => 'Connection refused',
            'duration_seconds' => null,
            'changes' => null,
            'suppressed_by_monitor_id' => null,
            'created_at' => '2026-11-01T10:00:00+00:00',
            'deliveries' => [
                [
                    'id' => $digest->id,
                    'channel' => ['id' => $slack->id, 'name' => 'Ops Slack', 'type' => 'slack'],
                    'status' => 'sent',
                    'attempts' => 1,
                    'last_error' => null,
                    'sent_at' => '2026-11-01T10:05:02+00:00',
                ],
                [
                    'id' => $failed->id,
                    'channel' => ['id' => $hook->id, 'name' => 'Hook', 'type' => 'webhook'],
                    'status' => 'failed',
                    'attempts' => 5,
                    'last_error' => 'HTTP 503',
                    'sent_at' => null,
                ],
            ],
        ]);

        // Deliveries are fetched with one query for the whole page, not one per alert.
        $deliveryQueries = array_filter($queries, static fn (array $q): bool => str_contains($q['query'], 'alert_deliveries'));
        $this->assertCount(1, $deliveryQueries);

        // Cursor pagination walks the same order.
        $first = $this->actingAs($this->editor)->getJson($this->url('/alerts?limit=3'))->assertOk()
            ->assertJsonPath('data.*.id', [$orphan->id, $changed->id, $up->id]);
        $cursor = $this->str($first->json('meta.next_cursor'));
        $this->actingAs($this->editor)->getJson($this->url('/alerts?limit=3&cursor='.urlencode($cursor)))->assertOk()
            ->assertJsonPath('data.*.id', [$down->id])
            ->assertJsonPath('data.0.deliveries.*.id', [$digest->id, $failed->id])
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_filters_and_isolation(): void
    {
        [$actor, $own, $foreign] = $this->crossWorkspaceFixture();
        $a = Monitor::factory()->for($own)->create();
        $b = Monitor::factory()->for($own)->create();
        $foreignMonitor = Monitor::factory()->for($foreign)->create();
        $foreignChannel = NotificationChannel::factory()->for($foreign)->create();

        $aDown = $this->event($own, $a, AlertKind::Down, '2026-11-01T10:00:00Z');
        $aUp = $this->event($own, $a, AlertKind::Up, '2026-11-02T10:00:00Z');
        $bDown = $this->event($own, $b, AlertKind::Down, '2026-11-03T10:00:00Z');
        $foreignEvent = $this->event($foreign, $foreignMonitor, AlertKind::Down, '2026-11-03T11:00:00Z');
        // A foreign delivery naming an own event id must never be shown.
        $this->delivery($foreign, $foreignChannel, [$aDown->id, $foreignEvent->id]);

        $get = fn (string $path) => $this->actingAs($actor)->getJson($this->url($path, $own))->assertOk();

        $get('/alerts')->assertJsonPath('data.*.id', [$bDown->id, $aUp->id, $aDown->id])
            ->assertJsonPath('data.2.deliveries', []);
        $get("/alerts?monitor_id={$a->id}")->assertJsonPath('data.*.id', [$aUp->id, $aDown->id]);
        $get('/alerts?kind=down')->assertJsonPath('data.*.id', [$bDown->id, $aDown->id]);
        $get('/alerts?since=2026-11-02T10:00:00Z')->assertJsonPath('data.*.id', [$bDown->id, $aUp->id]);
        $get('/alerts?since=2026-11-02T11:00:00%2B01:00')->assertJsonPath('data.*.id', [$bDown->id, $aUp->id]);
        $get("/alerts?monitor_id={$a->id}&kind=up&since=2026-11-01")->assertJsonPath('data.*.id', [$aUp->id]);

        // A foreign monitor id filters to nothing instead of leaking anything.
        $get("/alerts?monitor_id={$foreignMonitor->id}")->assertJsonPath('data', []);

        $get("/monitors/{$a->id}/alerts")->assertJsonPath('data.*.id', [$aUp->id, $aDown->id]);
        $get("/monitors/{$a->id}/alerts?kind=up")->assertJsonPath('data.*.id', [$aUp->id]);

        $this->actingAs($actor)->getJson($this->url('/alerts?kind=nope', $own))->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json');
        $this->actingAs($actor)->getJson($this->url('/alerts?since=yesterday', $own))->assertUnprocessable();
        // `since` is second-precision; fractional seconds are rejected.
        $this->actingAs($actor)->getJson($this->url('/alerts?since=2026-11-02T10:00:00.5Z', $own))->assertUnprocessable()
            ->assertJsonValidationErrors('since');
        $this->actingAs($actor)->getJson($this->url("/monitors/{$a->id}/alerts?since=2026-11-02T10:00:00.123456Z", $own))
            ->assertUnprocessable();
        $this->actingAs($actor)->getJson($this->url('/alerts?monitor_id=abc', $own))->assertUnprocessable();

        $this->assertCrossWorkspaceNotFound($actor, 'GET', $this->url("/monitors/{$foreignMonitor->id}/alerts", $own));
        $this->assertCrossWorkspaceNotFound($actor, 'GET', $this->url('/alerts', $foreign));
        $this->assertCrossWorkspaceNotFound($actor, 'GET', $this->url("/monitors/{$foreignMonitor->id}/alerts", $foreign));
    }

    public function test_delivery_lookup_is_bounded_by_the_oldest_alert_of_the_page(): void
    {
        $monitor = Monitor::factory()->for($this->ws)->create();
        $channel = NotificationChannel::factory()->for($this->ws)->create();
        $old = $this->event($this->ws, $monitor, AlertKind::Down, '2026-11-01T10:00:00Z');
        $new = $this->event($this->ws, $monitor, AlertKind::Up, '2026-11-01T12:00:00Z');

        // Written 4 minutes before the alert's own time (clock skew between hosts): inside the bound.
        $skewed = $this->delivery($this->ws, $channel, [$old->id], ['created_at' => CarbonImmutable::parse('2026-11-01T09:56:00Z')]);
        // A row older than the bound is not looked at, even though it names the alert.
        $this->delivery($this->ws, $channel, [$old->id], ['created_at' => CarbonImmutable::parse('2026-11-01T09:54:59Z')]);
        $later = $this->delivery($this->ws, $channel, [$new->id], ['created_at' => CarbonImmutable::parse('2026-11-01T12:00:01Z')]);

        DB::enableQueryLog();
        $this->actingAs($this->editor)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.*.id', [$new->id, $old->id])
            ->assertJsonPath('data.0.deliveries.*.id', [$later->id])
            ->assertJsonPath('data.1.deliveries.*.id', [$skewed->id]);
        $queries = array_values(array_filter(DB::getQueryLog(), static fn (array $q): bool => str_contains($q['query'], 'alert_deliveries')));
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('created_at', $queries[0]['query']);
        $this->assertContains('2026-11-01 09:55:00', $queries[0]['bindings']);

        // A smaller page still makes one delivery query.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->editor)->getJson($this->url('/alerts?limit=1'))->assertOk()
            ->assertJsonPath('data.0.deliveries.*.id', [$later->id]);
        $queries = array_values(array_filter(DB::getQueryLog(), static fn (array $q): bool => str_contains($q['query'], 'alert_deliveries')));
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
    }

    public function test_viewer_can_read_alerts_but_token_without_ability_cannot(): void
    {
        $viewer = User::factory()->memberOf($this->ws, WorkspaceRole::Viewer)->create();
        $monitor = Monitor::factory()->for($this->ws)->create();
        $event = $this->event($this->ws, $monitor, AlertKind::Down, '2026-11-01T10:00:00Z');

        $this->actingAs($viewer)->getJson($this->url())->assertOk()->assertJsonPath('data.0.id', $event->id);
        $this->actingAs($viewer)->getJson($this->url("/monitors/{$monitor->id}/alerts"))->assertOk()
            ->assertJsonPath('data.0.id', $event->id);

        $mint = fn (array $abilities): string => $this->str($this->actingAs($this->editor, 'sanctum')
            ->postJson($this->url('/tokens'), ['name' => 'ci', 'abilities' => $abilities])
            ->assertCreated()->json('token'));
        $as = function (string $token, string $path): TestResponse {
            $this->app['auth']->forgetGuards();

            return $this->withToken($token)->getJson($this->url($path));
        };

        $notifications = $mint(['notifications:read']);
        $monitors = $mint(['monitors:read']);
        $both = $mint(['notifications:read', 'monitors:read']);

        $as($notifications, '/alerts')->assertOk();
        $as($monitors, '/alerts')->assertForbidden()->assertHeader('Content-Type', 'application/problem+json');
        $as($notifications, "/monitors/{$monitor->id}/alerts")->assertForbidden();
        $as($monitors, "/monitors/{$monitor->id}/alerts")->assertForbidden();
        $as($both, "/monitors/{$monitor->id}/alerts")->assertOk()->assertJsonPath('data.0.id', $event->id);

        $this->app['auth']->forgetGuards();
        $this->withoutToken()->getJson($this->url())->assertUnauthorized();
    }
}
