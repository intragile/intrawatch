<?php

namespace Tests\Feature\Alerting;

use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorNotificationBindingTest extends TestCase
{
    use RefreshDatabase;

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

    private function url(string $path = ''): string
    {
        return "/api/v1/workspaces/{$this->ws->slug}/monitors{$path}";
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function body(array $extra = []): array
    {
        return $extra + ['name' => 'Site', 'type' => 'http', 'config' => ['url' => 'https://example.test']];
    }

    public function test_monitor_binding_rejects_foreign_channel_ids(): void
    {
        $own = NotificationChannel::factory()->for($this->ws)->create();
        $foreign = NotificationChannel::factory()->create();

        $this->actingAs($this->editor)->postJson($this->url(), $this->body(['notification_channel_ids' => [$own->id, $foreign->id]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['notification_channel_ids.1']);
        $this->actingAs($this->editor)->postJson($this->url(), $this->body(['notification_channel_ids' => [$own->id, $own->id]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['notification_channel_ids.0']);
        $this->actingAs($this->editor)->postJson($this->url(), $this->body(['notification_channel_ids' => range(1, 51)]))
            ->assertUnprocessable()->assertJsonValidationErrors(['notification_channel_ids']);

        $monitor = Monitor::factory()->for($this->ws)->create();
        $this->actingAs($this->editor)->patchJson($this->url("/{$monitor->id}"), ['notification_channel_ids' => [$foreign->id]])
            ->assertUnprocessable()->assertJsonValidationErrors(['notification_channel_ids.0']);
        $this->assertSame([], $monitor->notificationChannelIds());
        $this->assertDatabaseCount('monitors', 1);
    }

    public function test_binding_is_set_on_store_synced_on_update_and_audited(): void
    {
        $a = NotificationChannel::factory()->for($this->ws)->create();
        $b = NotificationChannel::factory()->for($this->ws)->create();
        $default = NotificationChannel::factory()->for($this->ws)->create(['is_default' => true]);

        // Explicit ids win over the defaults; an empty list means no channels.
        $id = $this->id($this->actingAs($this->editor)->postJson($this->url(), $this->body(['notification_channel_ids' => [$b->id, $a->id]]))
            ->assertCreated()->assertJsonPath('data.notification_channel_ids', [$a->id, $b->id])->json('data.id'));
        $empty = $this->id($this->actingAs($this->editor)->postJson($this->url(), $this->body(['notification_channel_ids' => []]))
            ->assertCreated()->assertJsonPath('data.notification_channel_ids', [])->json('data.id'));
        $this->actingAs($this->editor)->postJson($this->url(), $this->body())
            ->assertCreated()->assertJsonPath('data.notification_channel_ids', [$default->id]);

        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['name' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.notification_channel_ids', [$a->id, $b->id]);
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['notification_channel_ids' => [$b->id]])
            ->assertOk()->assertJsonPath('data.notification_channel_ids', [$b->id]);

        $audit = AuditEvent::query()->where('action', 'monitor.updated')->where('subject_id', (string) $id)->get();
        $diffs = $audit->map(fn (AuditEvent $e) => $e->changes['notification_channel_ids'] ?? null)->filter()->values();
        $this->assertSame([[[$a->id, $b->id], [$b->id]]], $diffs->all());

        $this->actingAs($this->editor)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.0.notification_channel_ids', [$b->id]);
        $this->actingAs($this->editor)->getJson($this->url("/{$empty}"))->assertJsonPath('data.notification_channel_ids', []);
    }

    public function test_alerting_settings_default_validate_and_merge_partially(): void
    {
        $id = $this->id($this->actingAs($this->editor)->postJson($this->url(), $this->body())
            ->assertCreated()
            ->assertJsonPath('data.alerting', [
                'resend_interval_minutes' => 0, 'notify_degraded' => true,
                'degraded_alert_after_seconds' => 300, 'notify_changes' => true,
            ])->json('data.id'));

        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['alerting' => ['resend_interval_minutes' => 30]])
            ->assertOk()
            ->assertJsonPath('data.alerting.resend_interval_minutes', 30)
            ->assertJsonPath('data.alerting.degraded_alert_after_seconds', 300);
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['alerting' => ['notify_degraded' => false, 'notify_changes' => false, 'degraded_alert_after_seconds' => 0]])
            ->assertOk()
            ->assertJsonPath('data.alerting.resend_interval_minutes', 30)
            ->assertJsonPath('data.alerting.notify_degraded', false)
            ->assertJsonPath('data.alerting.notify_changes', false)
            ->assertJsonPath('data.alerting.degraded_alert_after_seconds', 0);

        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['alerting' => ['resend_interval_minutes' => 10081]])
            ->assertUnprocessable()->assertJsonValidationErrors(['alerting.resend_interval_minutes']);
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['alerting' => ['resend_interval_minutes' => -1]])
            ->assertUnprocessable()->assertJsonValidationErrors(['alerting.resend_interval_minutes']);
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['alerting' => ['degraded_alert_after_seconds' => 86401]])
            ->assertUnprocessable()->assertJsonValidationErrors(['alerting.degraded_alert_after_seconds']);
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['alerting' => ['notify_changes' => 'maybe']])
            ->assertUnprocessable()->assertJsonValidationErrors(['alerting.notify_changes']);

        $this->actingAs($this->editor)->postJson($this->url(), $this->body(['alerting' => ['resend_interval_minutes' => 10080, 'notify_degraded' => false]]))
            ->assertCreated()
            ->assertJsonPath('data.alerting.resend_interval_minutes', 10080)
            ->assertJsonPath('data.alerting.notify_degraded', false)
            ->assertJsonPath('data.alerting.degraded_alert_after_seconds', 300);
    }

    public function test_bulk_notify_and_unnotify(): void
    {
        $channel = NotificationChannel::factory()->for($this->ws)->create();
        $foreign = NotificationChannel::factory()->create();
        $monitors = Monitor::factory()->for($this->ws)->count(3)->create();
        $other = Monitor::factory()->create();
        $ids = [];
        foreach ($monitors as $monitor) {
            $ids[] = $monitor->id;
        }
        $bulk = fn (array $body) => $this->actingAs($this->editor)->postJson($this->url('/bulk'), $body);

        $bulk(['action' => 'notify', 'ids' => $ids])->assertUnprocessable()->assertJsonValidationErrors(['channel_id']);
        $bulk(['action' => 'notify', 'ids' => $ids, 'channel_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors(['channel_id']);
        $bulk(['action' => 'pause', 'ids' => $ids, 'channel_id' => $channel->id])->assertUnprocessable()->assertJsonValidationErrors(['channel_id']);

        $bulk(['action' => 'notify', 'ids' => [...$ids, $other->id], 'channel_id' => $channel->id])->assertOk()->assertJson(['affected' => 3]);
        foreach ($monitors as $monitor) {
            $this->assertSame([$channel->id], $monitor->notificationChannelIds());
        }
        $this->assertSame([], $other->notificationChannelIds());
        $this->assertSame(3, AuditEvent::query()->where('action', 'monitor.updated')->count());

        // Repeating is a no-op and writes no further audit rows.
        $bulk(['action' => 'notify', 'ids' => $ids, 'channel_id' => $channel->id])->assertOk();
        $this->assertSame(3, AuditEvent::query()->where('action', 'monitor.updated')->count());

        $bulk(['action' => 'unnotify', 'ids' => [$ids[0], $ids[1]], 'channel_id' => $channel->id])->assertOk()->assertJson(['affected' => 2]);
        $this->assertSame([], Monitor::query()->findOrFail($ids[0])->notificationChannelIds());
        $this->assertSame([$channel->id], Monitor::query()->findOrFail($ids[2])->notificationChannelIds());
        $this->assertSame(5, AuditEvent::query()->where('action', 'monitor.updated')->count());
    }
}
