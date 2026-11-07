<?php

namespace Tests\Feature\Alerting;

use App\Alerting\Engine\AlertEvaluator;
use App\Alerting\Engine\AlertSignals;
use App\Enums\AlertKind;
use App\Enums\MonitorStatus;
use App\Enums\WorkspaceRole;
use App\Events\MonitorChanged;
use App\Events\MonitorStatusChanged;
use App\Jobs\EvaluateAlerts;
use App\Jobs\SendNotification;
use App\Models\AlertDelivery;
use App\Models\AlertEvent;
use App\Models\AlertState;
use App\Models\CheckResult;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Models\Workspace;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\DeadlineEvaluator;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Engine\RecordedBatch;
use App\Monitoring\Engine\ResultRecorder;
use App\Monitoring\MonitorActivation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class AlertPipelineTest extends TestCase
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
        // Only the sends are faked: alert evaluations run, on the sync queue unless a test says otherwise.
        if ($this->name() !== 'test_signal_and_job_commit_atomically') {
            Queue::fake([SendNotification::class]);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_group_recovery_via_api_reaches_the_alert_engine(): void
    {
        $group = Monitor::factory()->for($this->ws)->group('all')->create(['resend_interval_minutes' => 10]);
        $this->channel($group);
        $a = $this->monitor(['retries' => 0, 'parent_id' => $group->id]);
        $b = $this->monitor(['retries' => 0, 'parent_id' => $group->id]);
        $this->record($b, CheckOutcome::up(10), 0);
        $this->record($a, CheckOutcome::up(10), 0);
        $this->record($a, CheckOutcome::down('refused'), 60);
        $this->assertSame(['down'], $this->kinds($group));

        // Pausing the down child brings the group back up, and the alert engine hears about it.
        CarbonImmutable::setTestNow($this->t0->addSeconds(90));
        $this->actingAs($this->editor())->patchJson($this->api("/monitors/{$a->id}"), ['active' => false])->assertOk();
        $this->assertSame(MonitorStatus::Up, $group->state()->firstOrFail()->status);
        $this->assertSame(['down', 'up'], $this->kinds($group));

        // No reminder for an outage that is over.
        $this->tick(60 + 11 * 60);
        $this->assertSame(['down', 'up'], $this->kinds($group));

        // The other child goes down: the group's new outage is alerted.
        $this->record($b, CheckOutcome::down('refused'), 1010);
        $this->assertSame(MonitorStatus::Down, $group->state()->firstOrFail()->status);
        $this->assertSame(['down', 'up', 'down'], $this->kinds($group));
        $this->assertSame(3, AlertDelivery::count());
    }

    public function test_group_status_changes_from_every_api_path_reach_the_alert_engine(): void
    {
        $editor = $this->editor();
        $paths = [
            'action pause' => fn (Monitor $child) => $this->actingAs($editor)->postJson($this->api("/monitors/{$child->id}/pause"))->assertOk(),
            'bulk pause' => fn (Monitor $child) => $this->actingAs($editor)->postJson($this->api('/monitors/bulk'), ['action' => 'pause', 'ids' => [$child->id]])->assertOk(),
            'bulk delete' => fn (Monitor $child) => $this->actingAs($editor)->postJson($this->api('/monitors/bulk'), ['action' => 'delete', 'ids' => [$child->id]])->assertOk(),
            'delete' => fn (Monitor $child) => $this->actingAs($editor)->deleteJson($this->api("/monitors/{$child->id}"))->assertNoContent(),
            'move' => fn (Monitor $child) => $this->actingAs($editor)->patchJson($this->api("/monitors/{$child->id}"), ['parent_id' => null])->assertOk(),
        ];

        $seconds = 0;
        foreach ($paths as $label => $change) {
            $group = Monitor::factory()->for($this->ws)->group('all')->create();
            $this->channel($group);
            $sibling = $this->monitor(['retries' => 0, 'parent_id' => $group->id]);
            $child = $this->monitor(['retries' => 0, 'parent_id' => $group->id]);
            $this->record($sibling, CheckOutcome::up(10), $seconds);
            $this->record($child, CheckOutcome::up(10), $seconds);
            $this->record($child, CheckOutcome::down('refused'), $seconds + 60);
            $this->assertSame(['down'], $this->kinds($group), $label);

            CarbonImmutable::setTestNow($this->t0->addSeconds($seconds + 90));
            $change($child);
            $this->assertSame(['down', 'up'], $this->kinds($group), $label);
            $seconds += 600;
        }

        // A group config edit that turns the group down is alerted too.
        $group = Monitor::factory()->for($this->ws)->group('any')->create();
        $this->channel($group);
        $up = $this->monitor(['retries' => 0, 'parent_id' => $group->id]);
        $down = $this->monitor(['retries' => 0, 'parent_id' => $group->id]);
        $this->record($up, CheckOutcome::up(10), $seconds);
        $this->record($down, CheckOutcome::up(10), $seconds);
        $this->record($down, CheckOutcome::down('refused'), $seconds + 60);
        $this->assertSame([], $this->kinds($group));
        CarbonImmutable::setTestNow($this->t0->addSeconds($seconds + 90));
        $this->actingAs($editor)->patchJson($this->api("/monitors/{$group->id}"), ['config' => ['mode' => 'all']])->assertOk();
        $this->assertSame(['down'], $this->kinds($group));
    }

    public function test_alerting_setting_edits_reschedule_the_monitor(): void
    {
        $monitor = $this->monitor(['retries' => 0]);
        $this->channel($monitor);
        $this->record($monitor, CheckOutcome::up(10), 0);
        $this->record($monitor, CheckOutcome::down('refused'), 60);
        $this->assertSame(['down'], $this->kinds($monitor));
        $before = AlertState::findOrFail($monitor->id);
        $this->assertNull($before->due_at, 'no reminder without a resend interval');

        CarbonImmutable::setTestNow($this->t0->addSeconds(120));
        $this->actingAs($this->editor())->patchJson($this->api("/monitors/{$monitor->id}"), ['alerting' => ['resend_interval_minutes' => 30]])->assertOk();
        $dueAt = AlertState::findOrFail($monitor->id)->due_at;
        $this->assertNotNull($dueAt);
        $this->assertTrue($dueAt->lessThanOrEqualTo($this->t0->addSeconds(120)), "due at {$dueAt}");

        // The next tick plans the reminder by the new policy, and sends it when it falls due.
        $this->tick(180);
        $this->assertSame(['down'], $this->kinds($monitor));
        $this->assertTrue($this->t0->addSeconds(60 + 30 * 60)->equalTo(AlertState::findOrFail($monitor->id)->due_at ?? $this->t0));
        $this->tick(60 + 30 * 60);
        $this->assertSame(['down', 'still_down'], $this->kinds($monitor));

        // Edits that leave the alerting settings alone do not touch the schedule.
        $planned = AlertState::findOrFail($monitor->id)->due_at;
        $this->actingAs($this->editor())->patchJson($this->api("/monitors/{$monitor->id}"), ['name' => 'Renamed'])->assertOk();
        $this->assertEquals($planned, AlertState::findOrFail($monitor->id)->due_at);
    }

    public function test_api_group_changes_queue_their_evaluation_with_the_change(): void
    {
        $group = Monitor::factory()->for($this->ws)->group('all')->create();
        $this->channel($group);
        $sibling = $this->monitor(['retries' => 0, 'parent_id' => $group->id]);
        $child = $this->monitor(['retries' => 0, 'parent_id' => $group->id]);
        $this->record($sibling, CheckOutcome::up(10), 0);
        $this->record($child, CheckOutcome::up(10), 0);
        $this->record($child, CheckOutcome::down('refused'), 60);

        config(['queue.default' => 'database']);
        CarbonImmutable::setTestNow($this->t0->addSeconds(90));
        $this->actingAs($this->editor())->postJson($this->api('/monitors/bulk'), ['action' => 'pause', 'ids' => [$child->id]])->assertOk();

        $payload = $this->evaluationJobs()->sole()->payload;
        $this->assertIsString($payload);
        $job = unserialize($this->command($payload));
        $this->assertInstanceOf(EvaluateAlerts::class, $job);
        $this->assertSame([$group->id], array_keys(AlertSignals::fromPayload($job->signals)));
    }

    public function test_group_with_nothing_left_to_evaluate_sends_no_reminders(): void
    {
        foreach (['down' => CheckOutcome::down('refused'), 'up' => CheckOutcome::up(10)] as $label => $outcome) {
            CarbonImmutable::setTestNow($this->t0);
            $group = Monitor::factory()->for($this->ws)->group('all')->create(['resend_interval_minutes' => 10]);
            $this->channel($group);
            $a = $this->monitor(['retries' => 0, 'parent_id' => $group->id]);
            $b = $this->monitor(['retries' => 0, 'parent_id' => $group->id]);
            $this->record($b, CheckOutcome::up(10), 0);
            $this->record($a, CheckOutcome::up(10), 0);
            $this->record($a, CheckOutcome::down('refused'), 60);
            $this->assertSame(['down'], $this->kinds($group), $label);

            // Both children paused: the group is pending and has nothing to report on.
            CarbonImmutable::setTestNow($this->t0->addSeconds(90));
            $this->actingAs($this->editor())->postJson($this->api('/monitors/bulk'), ['action' => 'pause', 'ids' => [$a->id, $b->id]])->assertOk();
            $this->assertSame(MonitorStatus::Pending, $group->state()->firstOrFail()->status, $label);
            $this->tick(60 + 11 * 60);
            $this->assertSame(['down'], $this->kinds($group), "{$label}: no reminder while pending");
            $this->assertNull(AlertState::findOrFail($group->id)->due_at, $label);

            // A child comes back and decides the group's status again. It is resumed without a group recompute,
            // so the group moves from pending straight to the child's first result.
            CarbonImmutable::setTestNow($this->t0->addSeconds(900));
            MonitorActivation::apply($b->fresh() ?? $b, true);
            $this->record($b, $outcome, 960);
            if ($label === 'down') {
                $this->assertSame(MonitorStatus::Down, $group->state()->firstOrFail()->status);
                $this->assertSame(['down'], $this->kinds($group), 'the outage was alerted already');
                $this->tick(961);
                $this->assertSame(['down', 'still_down'], $this->kinds($group), 'reminders resume');
            } else {
                $this->assertSame(MonitorStatus::Up, $group->state()->firstOrFail()->status);
                $this->assertSame(['down', 'up'], $this->kinds($group));
            }
        }
    }

    public function test_status_change_creates_alert_event_and_delivery_per_bound_channel(): void
    {
        $monitor = $this->monitor(['retries' => 1]);
        $a = $this->channel($monitor);
        $b = $this->channel($monitor);
        $this->channel($monitor, ['active' => false]);
        $this->channel(Monitor::factory()->for($this->ws)->create());

        $this->record($monitor, CheckOutcome::up(20), 0);
        $this->record($monitor, CheckOutcome::down('HTTP 503'), 60);
        $this->assertSame(0, AlertEvent::count(), 'pending is not alerted');
        $this->record($monitor, CheckOutcome::down('HTTP 503'), 120);

        $event = AlertEvent::sole();
        $this->assertSame(AlertKind::Down, $event->kind);
        $this->assertSame(MonitorStatus::Down, $event->status);
        $this->assertSame($monitor->id, $event->monitor_id);
        $this->assertSame('HTTP 503', $event->message);
        $this->assertNull($event->suppressed_by_monitor_id);

        $deliveries = AlertDelivery::orderBy('notification_channel_id')->get();
        $this->assertSame([$a->id, $b->id], $deliveries->pluck('notification_channel_id')->all());
        foreach ($deliveries as $delivery) {
            $this->assertSame([$event->id], $delivery->alert_event_ids);
            $this->assertSame('queued', $delivery->status->value);
        }
        Queue::assertPushedOn('notifications', SendNotification::class);
        Queue::assertPushed(SendNotification::class, 2);
        $this->assertEqualsCanonicalizing($deliveries->pluck('id')->all(), Queue::pushed(SendNotification::class)->map(fn (mixed $job): int => $job instanceof SendNotification ? $job->deliveryId : 0)->all());

        $state = AlertState::findOrFail($monitor->id);
        $this->assertSame('down', $state->notified?->value);
        $this->assertTrue($this->t0->addSeconds(60)->equalTo($state->problem_since ?? $this->t0), 'the outage starts at the first failed check');
    }

    public function test_signal_and_job_commit_atomically(): void
    {
        config(['queue.default' => 'database']);
        $monitor = $this->monitor(['retries' => 0]);
        $this->channel($monitor);
        $this->record($monitor, CheckOutcome::up(20), 0);
        DB::table('jobs')->delete();

        CarbonImmutable::setTestNow($this->t0->addMinute());
        try {
            $this->recorder()->record([[MonitorSpec::fromModel($monitor->fresh() ?? $monitor), CheckOutcome::down('refused'), $this->t0->addMinute(), null]], true,
                function (RecordedBatch $batch): void {
                    throw new RuntimeException('later write failed');
                });
            $this->fail('the exception should propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('later write failed', $e->getMessage());
        }
        $this->assertSame(0, $this->evaluationJobs()->count(), 'no evaluation is queued for a rolled-back batch');
        $this->assertSame(1, CheckResult::where('monitor_id', $monitor->id)->count());

        $this->record($monitor, CheckOutcome::down('refused'), 60);
        $job = $this->evaluationJobs()->sole();
        $this->assertSame('default', $job->queue);
        $this->assertIsString($job->payload);
        $this->assertStringContainsString(str_replace('\\', '\\\\', EvaluateAlerts::class), $job->payload);
        $this->assertSame(0, AlertEvent::count(), 'the evaluation runs on the queue, not in the recorder');

        $this->assertSame(0, Artisan::call('queue:work', ['--stop-when-empty' => true, '--queue' => 'default']));

        $this->assertSame(AlertKind::Down, AlertEvent::sole()->kind);
        $delivery = AlertDelivery::sole();
        $this->assertSame(1, DB::table('jobs')->where('queue', 'notifications')->count());
        $payload = DB::table('jobs')->where('queue', 'notifications')->value('payload');
        $this->assertIsString($payload);
        $this->assertStringContainsString(str_replace('\\', '\\\\', SendNotification::class), $payload);
        $this->assertStringNotContainsString('hooks.slack.test', $payload, 'the job carries the delivery id only');
        $send = unserialize($this->command($payload));
        $this->assertInstanceOf(SendNotification::class, $send);
        $this->assertSame($delivery->id, $send->deliveryId);
    }

    public function test_reprocessing_the_same_signals_creates_no_duplicates(): void
    {
        $dns = $this->monitor(['retries' => 0], dns: true);
        $this->channel($dns);
        $t1 = $this->t0->addMinute();
        $payload = AlertSignals::toPayload(AlertSignals::fromEvents([
            new MonitorStatusChanged($this->ws->id, $dns->id, MonitorStatus::Up, MonitorStatus::Down, $t1, $this->t0, 'SERVFAIL'),
            new MonitorChanged($this->ws->id, $dns->id, 'dns.records', 'A:1.1.1.1', 'A:2.2.2.2', $t1),
        ]));

        $job = new EvaluateAlerts($payload);
        $job->handle($this->app->make(AlertEvaluator::class));
        // A retried job, and the same signals arriving again in a later job.
        $job->handle($this->app->make(AlertEvaluator::class));
        (new EvaluateAlerts($payload))->handle($this->app->make(AlertEvaluator::class));

        $this->assertSame(['changed', 'down'], AlertEvent::where('monitor_id', $dns->id)->orderBy('kind')->pluck('kind')->map(fn (mixed $k): string => $k instanceof AlertKind ? $k->value : '')->all());
        $this->assertSame(2, AlertDelivery::count());
        Queue::assertPushed(SendNotification::class, 2);

        // A signal that arrives after a newer one changes nothing.
        (new EvaluateAlerts(AlertSignals::toPayload(AlertSignals::fromEvents([
            new MonitorStatusChanged($this->ws->id, $dns->id, MonitorStatus::Pending, MonitorStatus::Up, $this->t0->addSeconds(30), $this->t0, ''),
        ]))))->handle($this->app->make(AlertEvaluator::class));
        $this->assertSame(2, AlertEvent::count());

        // A recorder batch committed twice: the second write sees the committed state and changes nothing.
        $http = $this->monitor(['retries' => 1]);
        $this->channel($http);
        $this->record($http, CheckOutcome::up(10), 0);
        $batch = [
            [MonitorSpec::fromModel($http->fresh() ?? $http), CheckOutcome::down('refused'), $this->t0->addSeconds(60), null],
            [MonitorSpec::fromModel($http->fresh() ?? $http), CheckOutcome::down('refused'), $this->t0->addSeconds(120), null],
        ];
        $this->recorder()->record($batch);
        $this->recorder()->record($batch);
        $this->assertSame(1, AlertEvent::where('monitor_id', $http->id)->where('kind', 'down')->count());
        $this->assertSame(1, AlertEvent::where('monitor_id', $http->id)->count());
    }

    public function test_child_of_down_group_is_suppressed_and_caught_up(): void
    {
        $group = Monitor::factory()->for($this->ws)->group('all')->create();
        $groupChannel = $this->channel($group);
        $child = $this->monitor(['retries' => 0, 'parent_id' => $group->id]);
        $childChannel = $this->channel($child);

        $this->record($child, CheckOutcome::up(10), 0);
        $this->record($child, CheckOutcome::down('refused'), 60);
        $this->assertSame(MonitorStatus::Down, $group->state()->firstOrFail()->status, 'child and group go down in one batch');

        $childAlert = AlertEvent::where('monitor_id', $child->id)->sole();
        $this->assertSame(AlertKind::Down, $childAlert->kind);
        $this->assertSame($group->id, $childAlert->suppressed_by_monitor_id);
        $groupAlert = AlertEvent::where('monitor_id', $group->id)->sole();
        $this->assertSame(AlertKind::Down, $groupAlert->kind);
        $this->assertNull($groupAlert->suppressed_by_monitor_id);
        $this->assertSame([$groupChannel->id], AlertDelivery::pluck('notification_channel_id')->all());
        $this->assertSame([$groupAlert->id], AlertDelivery::sole()->alert_event_ids);

        // Still suppressed at the first re-check.
        CarbonImmutable::setTestNow($this->t0->addSeconds(120));
        $this->assertSame(0, Artisan::call('intrawatch:alerts:tick'));
        $this->assertSame(1, AlertDelivery::count());

        // The group recovers (for instance because it was reconfigured) while the child is still down.
        $group->state()->update(['status' => MonitorStatus::Up]);
        CarbonImmutable::setTestNow($this->t0->addSeconds(180));
        $this->assertSame(0, Artisan::call('intrawatch:alerts:tick'));

        $caughtUp = AlertEvent::where('monitor_id', $child->id)->orderByDesc('id')->firstOrFail();
        $this->assertNotSame($childAlert->id, $caughtUp->id);
        $this->assertSame(AlertKind::Down, $caughtUp->kind);
        $this->assertNull($caughtUp->suppressed_by_monitor_id);
        $delivery = AlertDelivery::where('notification_channel_id', $childChannel->id)->sole();
        $this->assertSame([$caughtUp->id], $delivery->alert_event_ids);

        $this->assertSame(0, Artisan::call('intrawatch:alerts:tick'));
        $this->assertSame(2, AlertDelivery::count(), 'caught up once');
    }

    public function test_group_without_channels_does_not_suppress(): void
    {
        $group = Monitor::factory()->for($this->ws)->group('all')->create();
        $this->channel($group, ['active' => false]);
        $child = $this->monitor(['retries' => 0, 'parent_id' => $group->id]);
        $childChannel = $this->channel($child);

        $this->record($child, CheckOutcome::up(10), 0);
        $this->record($child, CheckOutcome::down('refused'), 60);

        $this->assertSame(MonitorStatus::Down, $group->state()->firstOrFail()->status);
        $childAlert = AlertEvent::where('monitor_id', $child->id)->sole();
        $this->assertNull($childAlert->suppressed_by_monitor_id);
        $this->assertSame([$childChannel->id], AlertDelivery::pluck('notification_channel_id')->all());
        $this->assertSame(1, AlertEvent::where('monitor_id', $group->id)->count(), 'the group alert is recorded, with nowhere to go');
    }

    public function test_tick_sends_degraded_after_hold_and_reminders(): void
    {
        $monitor = $this->monitor(['retries' => 0, 'degraded_alert_after_seconds' => 300, 'resend_interval_minutes' => 10]);
        $this->channel($monitor);

        $this->record($monitor, CheckOutcome::up(10), 0);
        $this->record($monitor, CheckOutcome::degraded('slow'), 60);
        $this->assertSame(0, AlertEvent::count(), 'degraded is held back');

        $this->tick(359);
        $this->assertSame(0, AlertEvent::count());
        $this->tick(360);
        $this->assertSame(['degraded'], $this->kinds($monitor));

        $this->record($monitor, CheckOutcome::down('refused'), 400);
        $this->assertSame(['degraded', 'down'], $this->kinds($monitor));

        $this->tick(999);
        $this->assertSame(['degraded', 'down'], $this->kinds($monitor));
        $this->tick(1000);
        $this->tick(1000);
        $this->assertSame(['degraded', 'down', 'still_down'], $this->kinds($monitor));
        $this->tick(1600);
        $this->assertSame(['degraded', 'down', 'still_down', 'still_down'], $this->kinds($monitor));
        $this->assertSame(4, AlertDelivery::count());

        $this->record($monitor, CheckOutcome::up(10), 1700);
        $this->assertSame(['degraded', 'down', 'still_down', 'still_down', 'up'], $this->kinds($monitor));
        $this->assertNull(AlertState::findOrFail($monitor->id)->due_at);
    }

    public function test_paused_or_deleted_monitor_signals_are_dropped(): void
    {
        $paused = $this->monitor();
        $deleted = $this->monitor();
        $this->channel($paused);
        $this->channel($deleted);
        $at = $this->t0->addMinute();
        $payload = AlertSignals::toPayload(AlertSignals::fromEvents([
            new MonitorStatusChanged($this->ws->id, $paused->id, MonitorStatus::Up, MonitorStatus::Down, $at, $this->t0, 'refused'),
            new MonitorStatusChanged($this->ws->id, $deleted->id, MonitorStatus::Up, MonitorStatus::Down, $at, $this->t0, 'refused'),
        ]));
        MonitorActivation::apply($paused, false);
        $deleted->delete();

        (new EvaluateAlerts($payload))->handle($this->app->make(AlertEvaluator::class));

        $this->assertSame(0, AlertEvent::count());
        $this->assertSame(0, AlertDelivery::count());
        $this->assertSame(0, AlertState::count());

        // A paused monitor with a reminder due keeps its state, but is not selected by every tick.
        AlertState::create(['monitor_id' => $paused->id, 'notified' => 'down', 'observed' => 'down', 'current' => 'down',
            'last_notified_at' => $this->t0, 'down_since' => $this->t0, 'problem_since' => $this->t0, 'due_at' => $this->t0]);
        $this->tick(60);
        $this->assertSame(0, AlertEvent::count());
        $state = AlertState::findOrFail($paused->id);
        $this->assertNull($state->due_at);
        $this->assertSame('down', $state->notified?->value);
    }

    public function test_push_heartbeat_path_also_alerts(): void
    {
        $monitor = Monitor::factory()->for($this->ws)->push(30)->create(['retries' => 0, 'interval_seconds' => 60]);
        $this->channel($monitor);

        $now = $this->t0->addSeconds(91);
        CarbonImmutable::setTestNow($now);
        $outcome = $this->app->make(DeadlineEvaluator::class)->evaluate(MonitorSpec::fromModel($monitor->fresh() ?? $monitor), $now)[0];
        $this->assertNotNull($outcome);
        $this->recorder()->record([[MonitorSpec::fromModel($monitor->fresh() ?? $monitor), $outcome, $now, null]]);

        $this->assertSame(MonitorStatus::Down, $monitor->state()->firstOrFail()->status);
        $event = AlertEvent::sole();
        $this->assertSame(AlertKind::Down, $event->kind);
        $this->assertSame('No heartbeat for 91 s', $event->message);
        $this->assertSame(1, AlertDelivery::count());

        // The heartbeat that ends the outage comes through the push endpoint.
        CarbonImmutable::setTestNow($now->addSeconds(30));
        $this->getJson("/api/v1/push/{$monitor->push_token}")->assertOk();
        $this->assertSame(['down', 'up'], $this->kinds($monitor));
        $this->assertSame(2, AlertDelivery::count());
    }

    public function test_a_due_tick_always_moves_the_due_time_past_now(): void
    {
        // A re-check interval of 0 makes the machine return "due now" for a monitor that stays suppressed.
        config(['alerting.suppression_recheck_seconds' => 0]);
        $group = Monitor::factory()->for($this->ws)->group('all')->create();
        $this->channel($group);
        $group->state()->update(['status' => MonitorStatus::Down]);
        $child = $this->monitor(['parent_id' => $group->id]);
        $this->channel($child);
        AlertState::create(['monitor_id' => $child->id, 'notified' => 'ok', 'observed' => 'down', 'current' => 'down',
            'down_since' => $this->t0, 'suppressed' => true, 'last_signal_at' => $this->t0, 'due_at' => $this->t0]);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e->message;
        });

        $this->tick(30);

        $this->assertSame(0, AlertEvent::count(), 'still suppressed');
        $dueAt = AlertState::findOrFail($child->id)->due_at;
        $this->assertNotNull($dueAt);
        $this->assertTrue($dueAt->equalTo($this->t0->addSeconds(90)), "due at {$dueAt}");
        $this->assertContains('An alert tick left a due time that is not in the future; it was moved on.', $logged);
    }

    public function test_tick_query_count_is_constant(): void
    {
        $channel = NotificationChannel::factory()->for($this->ws)->slack()->create();

        $this->dueReminders($channel, 1);
        $single = $this->countTickQueries();
        $this->assertSame(1, AlertEvent::count());

        $this->dueReminders($channel, 200);
        $many = $this->countTickQueries();
        $this->assertSame(201, AlertEvent::count());

        $this->assertSame($single, $many, 'ticking 200 due states takes as many queries as ticking one');
        // One delivery for the single reminder, one digest for the 200.
        $this->assertSame(2, AlertDelivery::count());
        $this->assertCount(200, AlertDelivery::orderByDesc('id')->firstOrFail()->alert_event_ids);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function monitor(array $attributes = [], bool $dns = false): Monitor
    {
        $factory = Monitor::factory()->for($this->ws);

        return ($dns ? $factory->dns() : $factory)->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function channel(Monitor $monitor, array $attributes = []): NotificationChannel
    {
        $channel = NotificationChannel::factory()->for($monitor->workspace()->firstOrFail())->create($attributes);
        $monitor->notificationChannels()->attach($channel->id);

        return $channel;
    }

    private function editor(): User
    {
        return User::factory()->memberOf($this->ws, WorkspaceRole::Editor)->create();
    }

    private function api(string $path): string
    {
        return '/api/v1/workspaces/'.$this->ws->slug.$path;
    }

    private function recorder(): ResultRecorder
    {
        return $this->app->make(ResultRecorder::class);
    }

    private function record(Monitor $monitor, CheckOutcome $outcome, int $seconds): void
    {
        $at = $this->t0->addSeconds($seconds);
        CarbonImmutable::setTestNow($at);
        $this->recorder()->record([[MonitorSpec::fromModel($monitor->fresh() ?? $monitor), $outcome, $at, null]]);
    }

    private function tick(int $seconds): void
    {
        CarbonImmutable::setTestNow($this->t0->addSeconds($seconds));
        $this->assertSame(0, Artisan::call('intrawatch:alerts:tick'));
    }

    /**
     * @return list<string>
     */
    private function kinds(Monitor $monitor): array
    {
        return array_values(AlertEvent::where('monitor_id', $monitor->id)->orderBy('id')->pluck('kind')->map(fn (mixed $k): string => $k instanceof AlertKind ? $k->value : '')->all());
    }

    private function dueReminders(NotificationChannel $channel, int $count): void
    {
        $since = $this->t0->subMinutes(30);
        $monitors = Monitor::factory()->for($this->ws)->count($count)->create(['resend_interval_minutes' => 10]);
        foreach ($monitors as $monitor) {
            AlertState::create([
                'monitor_id' => $monitor->id, 'notified' => 'down', 'observed' => 'down', 'current' => 'down',
                'problem_since' => $since, 'down_since' => $since, 'last_notified_at' => $this->t0->subMinutes(11),
                'last_signal_at' => $since, 'due_at' => $this->t0->subMinute(),
            ]);
            $monitor->notificationChannels()->attach($channel->id);
        }
    }

    private function countTickQueries(): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $before = $count;
        $this->assertSame(0, Artisan::call('intrawatch:alerts:tick'));

        return $count - $before;
    }

    private function evaluationJobs(): Builder
    {
        return DB::table('jobs')->where('payload', 'like', '%EvaluateAlerts%');
    }

    private function command(string $payload): string
    {
        $decoded = json_decode($payload, true);
        $this->assertIsArray($decoded);
        $this->assertIsArray($decoded['data'] ?? null);
        $this->assertIsString($decoded['data']['command'] ?? null);

        return $decoded['data']['command'];
    }
}
