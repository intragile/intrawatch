<?php

namespace Tests\Feature\Status;

use App\Enums\AlertKind;
use App\Enums\MonitorStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\SendNotification;
use App\Models\AlertEvent;
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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Drives the real recorder, group evaluator and alert engine around maintenance windows.
 */
class MaintenanceAlertingTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $t0;

    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t0 = CarbonImmutable::parse('2026-11-06 09:00:00', 'UTC');
        CarbonImmutable::setTestNow($this->t0);
        $this->ws = Workspace::factory()->create();
        // Only the sends are faked: alert evaluations run, so alert_events hold what the engine decided.
        Queue::fake([SendNotification::class]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse("2026-11-06 {$time}", 'UTC');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function monitor(array $attributes = []): Monitor
    {
        $monitor = Monitor::factory()->for($this->ws)->create(array_merge(['retries' => 0], $attributes));
        $channel = NotificationChannel::factory()->for($this->ws)->create();
        $monitor->notificationChannels()->attach($channel->id);

        return $monitor;
    }

    private function record(Monitor $monitor, CheckOutcome $outcome, string $time): void
    {
        $at = $this->at($time);
        CarbonImmutable::setTestNow($at);
        $this->app->make(ResultRecorder::class)->record([[MonitorSpec::fromModel($monitor->fresh() ?? $monitor), $outcome, $at, null]]);
    }

    private function tick(string $time): void
    {
        CarbonImmutable::setTestNow($this->at($time));
        $this->assertSame(0, Artisan::call('intrawatch:alerts:tick'));
    }

    /**
     * @return list<string>
     */
    private function kinds(Monitor $monitor): array
    {
        return array_values(AlertEvent::where('monitor_id', $monitor->id)->orderBy('id')->pluck('kind')->map(fn (mixed $k): string => $k instanceof AlertKind ? $k->value : '')->all());
    }

    private function stateOf(Monitor $monitor): MonitorStatus
    {
        return $monitor->state()->firstOrFail()->status;
    }

    private function window(Monitor $target, string $start, string $end): Maintenance
    {
        $maintenance = Maintenance::factory()->create(['workspace_id' => $this->ws->id, 'starts_at' => $this->at($start), 'ends_at' => $this->at($end)]);
        $maintenance->monitors()->attach($target->id);
        $this->app->make(MaintenanceOracle::class)->warm();

        return $maintenance;
    }

    public function test_outage_that_outlives_the_window_alerts_after_it(): void
    {
        // Case A: down and notified before the window, down throughout, down after it.
        $m = $this->monitor(['resend_interval_minutes' => 5]);
        $this->record($m, CheckOutcome::up(10), '09:00:00');
        $this->record($m, CheckOutcome::down('refused'), '09:50:00');
        $this->assertSame(['down'], $this->kinds($m));
        $this->window($m, '10:00:00', '10:30:00');

        foreach (['10:01:00', '10:10:00', '10:20:00', '10:29:00'] as $time) {
            $this->record($m, CheckOutcome::down('refused'), $time);
            $this->assertSame(MonitorStatus::Maintenance, $this->stateOf($m), $time);
            $this->tick($time);
        }
        $this->tick('10:29:30');
        $this->assertSame(['down'], $this->kinds($m), 'no reminder inside the window');

        $this->record($m, CheckOutcome::down('refused'), '10:31:00');
        $this->assertSame(MonitorStatus::Down, $this->stateOf($m));
        $this->assertSame(['down'], $this->kinds($m), 'neither a second Down nor an Up right after the window');

        $this->tick('10:37:00');
        $this->assertSame(['down', 'still_down'], $this->kinds($m));

        // Case B: up when the window starts, dies inside it, still down after it.
        $b = $this->monitor();
        $this->record($b, CheckOutcome::up(10), '10:38:00');
        $this->window($b, '10:40:00', '10:50:00');
        $this->record($b, CheckOutcome::up(10), '10:41:00');
        $this->record($b, CheckOutcome::down('refused'), '10:45:00');
        $this->assertSame(MonitorStatus::Maintenance, $this->stateOf($b));
        $this->assertSame([], $this->kinds($b));
        $this->record($b, CheckOutcome::down('refused'), '10:49:00');
        $this->tick('10:49:30');
        $this->assertSame([], $this->kinds($b));

        $this->record($b, CheckOutcome::down('refused'), '10:51:00');
        $this->assertSame(['down'], $this->kinds($b));
        $event = AlertEvent::where('monitor_id', $b->id)->firstOrFail();
        $this->assertTrue($event->created_at->gte($this->at('10:50:00')), 'the Down alert is created after the window');
    }

    private function evaluationQueuedFor(Monitor $monitor): bool
    {
        foreach (DB::table('jobs')->pluck('payload') as $payload) {
            if (is_string($payload) && str_contains($payload, 'EvaluateAlerts') && preg_match('/monitor_id\\\\?";i:'.$monitor->id.';/', $payload) === 1) {
                return true;
            }
        }

        return false;
    }

    private function editor(): User
    {
        return User::factory()->memberOf($this->ws, WorkspaceRole::Editor)->create();
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/workspaces/{$this->ws->slug}/maintenances{$path}";
    }

    /**
     * A down child in a group that has an alert channel.
     *
     * @return array{0: Monitor, 1: Monitor}
     */
    private function downGroup(): array
    {
        $group = Monitor::factory()->for($this->ws)->group('all')->create();
        $channel = NotificationChannel::factory()->for($this->ws)->create();
        $group->notificationChannels()->attach($channel->id);
        $child = Monitor::factory()->for($this->ws)->create(['parent_id' => $group->id, 'retries' => 0]);
        $this->record($child, CheckOutcome::up(10), '09:00:00');
        $this->record($child, CheckOutcome::down('refused'), '09:10:00');

        return [$group, $child];
    }

    public function test_creating_an_active_window_mutes_groups_immediately(): void
    {
        [$group, $child] = $this->downGroup();
        $this->assertSame(MonitorStatus::Down, $this->stateOf($group));
        $this->assertSame(['down'], $this->kinds($group));

        config(['queue.default' => 'database']);
        DB::table('jobs')->delete();
        CarbonImmutable::setTestNow($this->at('09:30:00'));
        $this->actingAs($this->editor())->postJson($this->url(), [
            'title' => 'Now', 'strategy' => 'once', 'starts_at' => '2026-11-06T09:00:00Z', 'ends_at' => '2026-11-06T12:00:00Z', 'monitor_ids' => [$group->id],
        ])->assertCreated()->assertJsonPath('data.in_progress', true);

        $this->assertSame(MonitorStatus::Maintenance, $this->stateOf($group));
        $this->assertNotNull($child->fresh()?->check_requested_at, 'the child is asked to check now');
        $this->assertTrue($this->evaluationQueuedFor($group), 'an evaluation for the group is queued with the transaction');
    }

    public function test_deleting_an_active_window_unmutes_through_the_engine(): void
    {
        [$group, $child] = $this->downGroup();
        CarbonImmutable::setTestNow($this->at('09:20:00'));
        $maintenance = $this->window($group, '09:00:00', '12:00:00');
        // The group is recomputed into maintenance by the window.
        $this->app->make(GroupEvaluator::class)->recompute([$group->id]);
        $this->assertSame(MonitorStatus::Maintenance, $this->stateOf($group));
        DB::table('jobs')->delete();

        config(['queue.default' => 'database']);
        CarbonImmutable::setTestNow($this->at('09:30:00'));
        $this->actingAs($this->editor())->deleteJson($this->url("/{$maintenance->id}"))->assertNoContent();

        $this->assertSame(MonitorStatus::Down, $this->stateOf($group), 'recomputed to the real status in the same transaction');
        $this->assertNotNull($child->fresh()?->check_requested_at);
        $this->assertTrue($this->evaluationQueuedFor($group), 'an EvaluateAlerts job was written with the transaction');

        // The evaluation finds an outage that was already notified: no second Down, and no Up in between.
        $this->assertSame(0, Artisan::call('queue:work', ['--stop-when-empty' => true, '--queue' => 'default', '--memory' => 4096]));
        $this->assertSame(['down'], $this->kinds($group));
    }
}
