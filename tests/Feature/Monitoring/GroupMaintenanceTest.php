<?php

namespace Tests\Feature\Monitoring;

use App\Enums\AlertKind;
use App\Enums\MonitorStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\SendNotification;
use App\Models\AlertEvent;
use App\Models\CheckResult;
use App\Models\Maintenance;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Models\Workspace;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\GroupEvaluator;
use App\Monitoring\Engine\MaintenanceOracle;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Engine\ResultRecorder;
use App\Monitoring\Engine\StatusMachine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GroupMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $t0;

    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t0 = CarbonImmutable::parse('2026-11-06 10:00:00', 'UTC');
        CarbonImmutable::setTestNow($this->t0);
        $this->ws = Workspace::factory()->create();
        Queue::fake([SendNotification::class]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function statusOf(Monitor $monitor): MonitorStatus
    {
        return $monitor->state()->firstOrFail()->status;
    }

    private function setStatus(Monitor $monitor, MonitorStatus $status): void
    {
        $monitor->state()->update(['status' => $status]);
    }

    private function child(Monitor $group, MonitorStatus $status): Monitor
    {
        $child = Monitor::factory()->for($this->ws)->create(['parent_id' => $group->id, 'retries' => 0]);
        $this->setStatus($child, $status);

        return $child;
    }

    private function recompute(Monitor $group): void
    {
        $this->app->make(GroupEvaluator::class)->recompute([$group->id]);
    }

    /**
     * @param  list<Monitor>  $targets
     */
    private function window(array $targets, CarbonImmutable $start, ?CarbonImmutable $end): Maintenance
    {
        $maintenance = Maintenance::factory()->create(['workspace_id' => $this->ws->id, 'starts_at' => $start, 'ends_at' => $end]);
        $maintenance->monitors()->attach(array_map(fn (Monitor $m): int => $m->id, $targets));
        $this->app->make(MaintenanceOracle::class)->warm();

        return $maintenance;
    }

    public function test_group_in_window_is_maintenance(): void
    {
        $group = Monitor::factory()->for($this->ws)->group('all')->create();
        $this->child($group, MonitorStatus::Down);
        $this->window([$group], $this->t0->subMinute(), $this->t0->addHour());

        $this->recompute($group);

        $this->assertSame(MonitorStatus::Maintenance, $this->statusOf($group));
        $this->assertSame(StatusMachine::MAINTENANCE_MESSAGE, CheckResult::where('monitor_id', $group->id)->firstOrFail()->message);
    }

    public function test_group_with_all_children_in_maintenance_is_maintenance_not_pending(): void
    {
        $group = Monitor::factory()->for($this->ws)->group('all')->create();
        $this->child($group, MonitorStatus::Maintenance);
        $this->child($group, MonitorStatus::Maintenance);
        $paused = $this->child($group, MonitorStatus::Paused);
        $paused->update(['active' => false]);

        $this->recompute($group);
        $this->assertSame(MonitorStatus::Maintenance, $this->statusOf($group));
        $this->assertSame(StatusMachine::MAINTENANCE_MESSAGE, CheckResult::where('monitor_id', $group->id)->firstOrFail()->message);

        // Paused children alone leave the group pending.
        $other = Monitor::factory()->for($this->ws)->group('all')->create();
        $this->child($other, MonitorStatus::Paused)->update(['active' => false]);
        $this->recompute($other);
        $this->assertSame(MonitorStatus::Pending, $this->statusOf($other));

        // One counted child brings the group back to a real status.
        $this->child($group, MonitorStatus::Up);
        $this->recompute($group);
        $this->assertSame(MonitorStatus::Up, $this->statusOf($group));
    }

    public function test_all_mode_pending_child_is_neutral(): void
    {
        $this->assertSame(MonitorStatus::Up, $this->allModeStatus([MonitorStatus::Up, MonitorStatus::Pending]));
        $this->assertSame(MonitorStatus::Pending, $this->allModeStatus([MonitorStatus::Pending]));
        $this->assertSame(MonitorStatus::Down, $this->allModeStatus([MonitorStatus::Down, MonitorStatus::Pending]));
        $this->assertSame(MonitorStatus::Degraded, $this->allModeStatus([MonitorStatus::Degraded, MonitorStatus::Pending]));
    }

    /**
     * @param  list<MonitorStatus>  $statuses
     */
    private function allModeStatus(array $statuses): MonitorStatus
    {
        $group = Monitor::factory()->for($this->ws)->group('all')->create();
        foreach ($statuses as $status) {
            $this->child($group, $status);
        }
        $this->recompute($group);

        return $this->statusOf($group);
    }

    public function test_resuming_a_child_of_a_down_group_sends_no_degraded(): void
    {
        $editor = User::factory()->memberOf($this->ws, WorkspaceRole::Editor)->create();
        $group = Monitor::factory()->for($this->ws)->group('all')->create();
        $channel = NotificationChannel::factory()->for($this->ws)->create();
        $group->notificationChannels()->attach($channel->id);
        $a = $this->child($group, MonitorStatus::Pending);
        $b = $this->child($group, MonitorStatus::Pending);
        $base = "/api/v1/workspaces/{$this->ws->slug}/monitors/{$a->id}";

        $this->record($b, CheckOutcome::up(10), 0);
        $this->record($a, CheckOutcome::up(10), 0);
        $this->record($a, CheckOutcome::down('refused'), 60);
        $this->assertSame(MonitorStatus::Down, $this->statusOf($group));

        CarbonImmutable::setTestNow($this->t0->addSeconds(90));
        $this->actingAs($editor)->patchJson($base, ['active' => false])->assertOk();
        $this->assertSame(MonitorStatus::Up, $this->statusOf($group));

        CarbonImmutable::setTestNow($this->t0->addSeconds(120));
        $this->actingAs($editor)->patchJson($base, ['active' => true])->assertOk();
        $this->assertSame(MonitorStatus::Pending, $this->statusOf($a));
        $this->assertSame(MonitorStatus::Up, $this->statusOf($group));

        $this->record($a, CheckOutcome::down('refused'), 150);
        $this->assertSame(MonitorStatus::Down, $this->statusOf($group));

        $kinds = AlertEvent::where('monitor_id', $group->id)->orderBy('id')->pluck('kind')
            ->map(fn (mixed $k): string => $k instanceof AlertKind ? $k->value : '')->all();
        $this->assertSame(['down', 'up', 'down'], $kinds);
    }

    public function test_group_maintenance_mutes_alerts(): void
    {
        $group = Monitor::factory()->for($this->ws)->group('all')->create(['resend_interval_minutes' => 10]);
        $channel = NotificationChannel::factory()->for($this->ws)->create();
        $group->notificationChannels()->attach($channel->id);
        $a = $this->child($group, MonitorStatus::Pending);
        $this->record($a, CheckOutcome::up(10), 0);
        $this->record($a, CheckOutcome::down('refused'), 60);
        $this->assertSame(MonitorStatus::Down, $this->statusOf($group));

        // The window starts; the next recompute turns the group into maintenance.
        CarbonImmutable::setTestNow($this->t0->addSeconds(120));
        $this->window([$group], $this->t0->addSeconds(100), $this->t0->addHours(2));
        $this->record($a, CheckOutcome::down('refused'), 120);
        $this->assertSame(MonitorStatus::Maintenance, $this->statusOf($group));

        foreach ([30 * 60, 60 * 60] as $seconds) {
            CarbonImmutable::setTestNow($this->t0->addSeconds($seconds));
            $this->assertSame(0, Artisan::call('intrawatch:alerts:tick'));
        }

        $kinds = AlertEvent::where('monitor_id', $group->id)->orderBy('id')->pluck('kind')
            ->map(fn (mixed $k): string => $k instanceof AlertKind ? $k->value : '')->all();
        $this->assertNotContains('still_down', $kinds);
        $this->assertSame(['down'], $kinds);
    }

    private function record(Monitor $monitor, CheckOutcome $outcome, int $seconds): void
    {
        $at = $this->t0->addSeconds($seconds);
        CarbonImmutable::setTestNow($at);
        $this->app->make(ResultRecorder::class)->record([[MonitorSpec::fromModel($monitor->fresh() ?? $monitor), $outcome, $at, null]]);
    }
}
