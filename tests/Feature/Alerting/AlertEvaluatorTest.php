<?php

namespace Tests\Feature\Alerting;

use App\Alerting\Engine\AlertEvaluator;
use App\Alerting\Engine\AlertSignals;
use App\Alerting\Engine\Signals\ChangeSignal;
use App\Alerting\Engine\Signals\StatusSignal;
use App\Alerting\Engine\SuppressionMap;
use App\Enums\MonitorStatus;
use App\Events\AlertRaised;
use App\Events\ChecksRecorded;
use App\Events\MonitorChanged;
use App\Events\MonitorStatusChanged;
use App\Jobs\SendNotification;
use App\Models\AlertDelivery;
use App\Models\AlertEvent;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\Workspace;
use App\Monitoring\Engine\PostCommit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingQueue;
use Tests\TestCase;

class AlertEvaluatorTest extends TestCase
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
        if ($this->name() !== 'test_sends_wait_for_commit_when_the_queue_is_not_transactional') {
            Queue::fake([SendNotification::class]);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_signals_merge_changes_per_check_and_order_status_first(): void
    {
        $t1 = $this->t0->addSeconds(60)->addMilliseconds(250);
        $signals = AlertSignals::fromEvents([
            new MonitorChanged($this->ws->id, 7, 'tls.fingerprint', 'aa', 'bb', $t1),
            new ChecksRecorded($this->ws->id, []),
            new MonitorStatusChanged($this->ws->id, 7, MonitorStatus::Up, MonitorStatus::Down, $t1, $this->t0, 'expired'),
            new MonitorChanged($this->ws->id, 7, 'tls.expiry_band', '30d', 'expired', $t1),
            new MonitorStatusChanged($this->ws->id, 3, MonitorStatus::Pending, MonitorStatus::Up, $this->t0, $this->t0, ''),
            new MonitorStatusChanged($this->ws->id, 7, MonitorStatus::Pending, MonitorStatus::Up, $this->t0, $this->t0->subMinute(), ''),
        ]);

        $this->assertSame([3, 7], array_keys($signals));
        $seven = $signals[7];
        $this->assertCount(3, $seven);
        $this->assertInstanceOf(StatusSignal::class, $seven[0]);
        $this->assertSame(MonitorStatus::Up, $seven[0]->to);
        $this->assertInstanceOf(StatusSignal::class, $seven[1]);
        $this->assertSame(MonitorStatus::Down, $seven[1]->to);
        $this->assertSame(0, $seven[1]->at->micro, 'times are cut to whole seconds');
        $this->assertInstanceOf(ChangeSignal::class, $seven[2]);
        $this->assertSame(['tls.fingerprint', 'tls.expiry_band'], array_column($seven[2]->changes, 'key'));

        $this->assertEquals($signals, AlertSignals::fromPayload(AlertSignals::toPayload($signals)));
        $payload = json_encode(AlertSignals::toPayload($signals));
        $this->assertIsString($payload);
        $this->assertStringNotContainsString('{"date"', $payload, 'the payload holds scalars only');
    }

    public function test_payload_text_is_cleaned_so_the_queue_insert_cannot_fail(): void
    {
        $payload = AlertSignals::toPayload(AlertSignals::fromEvents([
            new MonitorStatusChanged($this->ws->id, 1, MonitorStatus::Up, MonitorStatus::Down, $this->t0, $this->t0, "bad \xff\x00text"),
            new MonitorChanged($this->ws->id, 1, 'dns.records', "A:\xfe", 'A:2.2.2.2', $this->t0),
        ]));

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('\u0000', $json);
        $this->assertSame('bad ?text', $payload[0]['message']);
    }

    public function test_digest_per_channel_above_threshold(): void
    {
        config(['alerting.digest.threshold' => 5]);
        $slack = NotificationChannel::factory()->for($this->ws)->slack()->create();
        $webhook = NotificationChannel::factory()->for($this->ws)->webhook()->create();
        $monitors = Monitor::factory()->for($this->ws)->count(6)->create();
        foreach ($monitors as $monitor) {
            $monitor->notificationChannels()->attach([$slack->id, $webhook->id]);
        }

        $count = $this->evaluateDown(array_values($monitors->all()));

        $this->assertSame(6, $count);
        $digest = AlertDelivery::where('notification_channel_id', $slack->id)->sole();
        $this->assertSame(AlertEvent::orderBy('id')->pluck('id')->all(), $digest->alert_event_ids);
        $this->assertSame(6, AlertDelivery::where('notification_channel_id', $webhook->id)->count(), 'channels without digests get one delivery per alert');
        Queue::assertPushed(SendNotification::class, 7);
    }

    public function test_at_the_threshold_each_alert_is_delivered_alone(): void
    {
        config(['alerting.digest.threshold' => 5]);
        $slack = NotificationChannel::factory()->for($this->ws)->slack()->create();
        $monitors = Monitor::factory()->for($this->ws)->count(5)->create();
        foreach ($monitors as $monitor) {
            $monitor->notificationChannels()->attach($slack->id);
        }

        $this->evaluateDown(array_values($monitors->all()));

        $this->assertSame(5, AlertDelivery::count());
        foreach (AlertDelivery::all() as $delivery) {
            $this->assertCount(1, $delivery->alert_event_ids);
        }
    }

    public function test_alert_raised_is_dispatched_after_commit_per_workspace(): void
    {
        $other = Workspace::factory()->create();
        $a = Monitor::factory()->for($this->ws)->create();
        $b = Monitor::factory()->for($other)->create();
        $baseline = DB::transactionLevel();
        /** @var list<array{0: AlertRaised, 1: int}> $seen */
        $seen = [];
        Event::listen(AlertRaised::class, function (AlertRaised $e) use (&$seen): void {
            $seen[] = [$e, DB::transactionLevel()];
        });

        $this->evaluateDown([$a, $b]);

        $this->assertCount(2, $seen);
        foreach ($seen as [, $level]) {
            $this->assertSame($baseline, $level, 'dispatched after the evaluation committed');
        }
        [$first] = $seen[0];
        $this->assertSame($this->ws->id, $first->workspaceId);
        $this->assertSame(AlertEvent::where('monitor_id', $a->id)->pluck('id')->all(), $first->alertEventIds);
        $this->assertSame('alert.raised', $first->broadcastAs());
        $this->assertSame(['alert_event_ids' => $first->alertEventIds], $first->broadcastWith());
        $this->assertSame("private-workspace.{$this->ws->id}", $first->broadcastOn()[0]->name);
        $this->assertSame($other->id, $seen[1][0]->workspaceId);
    }

    public function test_sends_wait_for_commit_when_the_queue_is_not_transactional(): void
    {
        RecordingQueue::install('recording');
        $this->assertFalse(PostCommit::queueJoinsTransaction());
        $channel = NotificationChannel::factory()->for($this->ws)->webhook()->create();
        $monitors = Monitor::factory()->for($this->ws)->count(2)->create();
        foreach ($monitors as $monitor) {
            $monitor->notificationChannels()->attach($channel->id);
        }
        $baseline = DB::transactionLevel();

        $this->evaluateDown(array_values($monitors->all()));

        $sends = array_values(array_filter(RecordingQueue::$pushed, fn (array $entry): bool => $entry[0] instanceof SendNotification));
        $this->assertCount(2, $sends);
        foreach ($sends as [$job, $level]) {
            $this->assertSame($baseline, $level, 'queued after the evaluation committed');
            $this->assertInstanceOf(SendNotification::class, $job);
            $this->assertContains($job->deliveryId, AlertDelivery::pluck('id')->all());
        }
    }

    public function test_suppression_map_finds_the_nearest_alerting_ancestor(): void
    {
        $top = Monitor::factory()->for($this->ws)->group()->create();
        $middle = Monitor::factory()->for($this->ws)->group()->create(['parent_id' => $top->id]);
        $leaf = Monitor::factory()->for($this->ws)->create(['parent_id' => $middle->id]);
        $loose = Monitor::factory()->for($this->ws)->create();
        $top->notificationChannels()->attach(NotificationChannel::factory()->for($this->ws)->create()->id);
        $middle->notificationChannels()->attach(NotificationChannel::factory()->for($this->ws)->create(['active' => false])->id);
        $map = new SuppressionMap;

        $this->assertSame([$leaf->id => null, $loose->id => null], $map->for([$leaf->id, $loose->id]));

        $middle->state()->update(['status' => MonitorStatus::Down]);
        $this->assertSame([$leaf->id => null], $map->for([$leaf->id]), 'a down group without an active channel does not suppress');

        $top->state()->update(['status' => MonitorStatus::Down]);
        $this->assertSame([$leaf->id => $top->id, $middle->id => $top->id, $top->id => null], $map->for([$leaf->id, $middle->id, $top->id]));

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $map->for([$leaf->id, $middle->id, $top->id, $loose->id]);
        $this->assertLessThanOrEqual(3, $count);
    }

    /**
     * @param  list<Monitor>  $monitors
     */
    private function evaluateDown(array $monitors): int
    {
        $events = [];
        foreach ($monitors as $monitor) {
            $events[] = new MonitorStatusChanged($monitor->workspace_id, $monitor->id, MonitorStatus::Up, MonitorStatus::Down, $this->t0, $this->t0->subMinute(), 'refused');
        }

        return $this->app->make(AlertEvaluator::class)->evaluate(AlertSignals::fromEvents($events), $this->t0);
    }
}
