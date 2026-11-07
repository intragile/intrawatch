<?php

namespace Tests\Feature\Alerting;

use App\Alerting\Engine\AlertClass;
use App\Enums\AlertKind;
use App\Enums\ChannelType;
use App\Enums\DeliveryStatus;
use App\Enums\MonitorStatus;
use App\Models\AlertDelivery;
use App\Models\AlertEvent;
use App\Models\AlertState;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AlertingModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_channel_secrets_are_encrypted_and_hidden(): void
    {
        $c = NotificationChannel::factory()->slack()->create(['secrets' => ['webhook_url' => 'https://hooks.slack.test/T/B/s3cr3t']]);

        $this->assertArrayNotHasKey('secrets', $c->toArray());
        $stored = DB::table('notification_channels')->value('secrets');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('s3cr3t', $stored);
        $this->assertSame(['webhook_url' => 'https://hooks.slack.test/T/B/s3cr3t'], $c->fresh()?->secrets);
        $this->assertSame(ChannelType::Slack, NotificationChannel::query()->findOrFail($c->id)->type);
    }

    public function test_channel_defaults_and_workspace_relation(): void
    {
        $created = NotificationChannel::factory()->webhook()->create();
        $c = NotificationChannel::query()->findOrFail($created->id);
        $workspace = Workspace::query()->findOrFail($c->workspace_id);

        $this->assertTrue($c->active);
        $this->assertFalse($c->is_default);
        $this->assertSame('POST', $c->config['method']);
        $this->assertTrue($workspace->notificationChannels->contains($c));
    }

    public function test_monitor_alerting_defaults(): void
    {
        $m = Monitor::factory()->http()->create()->fresh();

        $this->assertNotNull($m);
        $this->assertSame(0, $m->resend_interval_minutes);
        $this->assertTrue($m->notify_degraded);
        $this->assertSame(300, $m->degraded_alert_after_seconds);
        $this->assertTrue($m->notify_changes);
    }

    public function test_channels_attach_to_monitors(): void
    {
        $ws = Workspace::factory()->create();
        $m = Monitor::factory()->http()->create(['workspace_id' => $ws->id]);
        $c = NotificationChannel::factory()->slack()->create(['workspace_id' => $ws->id]);

        $m->notificationChannels()->attach($c->id);

        $this->assertTrue($m->notificationChannels()->first()?->is($c));
        $this->assertTrue($c->monitors()->first()?->is($m));
    }

    public function test_deleting_monitor_or_channel_cleans_pivot_and_keeps_history(): void
    {
        $ws = Workspace::factory()->create();
        $m1 = Monitor::factory()->http()->create(['workspace_id' => $ws->id]);
        $m2 = Monitor::factory()->http()->create(['workspace_id' => $ws->id]);
        $c = NotificationChannel::factory()->slack()->create(['workspace_id' => $ws->id]);
        $m1->notificationChannels()->attach($c->id);
        $m2->notificationChannels()->attach($c->id);

        $event = AlertEvent::create([
            'workspace_id' => $ws->id, 'monitor_id' => $m1->id, 'kind' => AlertKind::Down,
            'status' => MonitorStatus::Down, 'previous_status' => MonitorStatus::Up, 'message' => 'down',
            'changes' => [['key' => 'cert', 'old' => 'a', 'new' => 'b']],
        ]);
        $delivery = AlertDelivery::create([
            'workspace_id' => $ws->id, 'notification_channel_id' => $c->id,
            'alert_event_ids' => [$event->id], 'status' => DeliveryStatus::Queued,
        ]);
        AlertState::create(['monitor_id' => $m1->id, 'recent_changes' => [1, 2]]);

        $m1->delete();

        $this->assertSame(1, DB::table('monitor_notification')->count());
        $this->assertNull($event->fresh()?->monitor_id);
        $this->assertSame(0, AlertState::query()->count());

        $c->delete();

        $this->assertSame(0, DB::table('monitor_notification')->count());
        $this->assertNull($delivery->fresh()?->notification_channel_id);
        $keptDelivery = AlertDelivery::query()->findOrFail($delivery->id);
        $keptEvent = AlertEvent::query()->findOrFail($event->id);
        $this->assertSame([$event->id], $keptDelivery->alert_event_ids);
        $this->assertSame(DeliveryStatus::Queued, $keptDelivery->status);
        $this->assertSame(AlertKind::Down, $keptEvent->kind);
        // MySQL reorders JSON object keys, so compare as an unordered map.
        $this->assertEquals([['key' => 'cert', 'old' => 'a', 'new' => 'b']], $keptEvent->changes);
    }

    public function test_alert_state_round_trips_casts(): void
    {
        $m = Monitor::factory()->http()->create();
        AlertState::create([
            'monitor_id' => $m->id, 'notified' => 'ok', 'observed' => 'down', 'current' => MonitorStatus::Down,
            'problem_since' => now(), 'recent_changes' => [100, 200],
        ]);

        $s = AlertState::query()->findOrFail($m->id);

        $this->assertSame(AlertClass::Ok, $s->notified);
        $this->assertSame(AlertClass::Down, $s->observed);
        $this->assertSame(MonitorStatus::Down, $s->current);
        $this->assertFalse($s->flapping);
        $this->assertSame(0, $s->reminders_sent);
        $this->assertSame([100, 200], $s->recent_changes);
    }
}
