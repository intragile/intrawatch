<?php

namespace Tests\Feature\Alerting;

use App\Alerting\Channels\ChannelSender;
use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Channels\Senders\SlackSender;
use App\Alerting\DeliveryRunner;
use App\Alerting\Messages\OutgoingNotification;
use App\Alerting\Transport\DeliveryFailure;
use App\Alerting\Transport\OutboundHttp;
use App\Enums\AlertKind;
use App\Enums\DeliveryStatus;
use App\Enums\MonitorStatus;
use App\Jobs\SendNotification;
use App\Models\AlertDelivery;
use App\Models\AlertEvent;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\Workspace;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Engine\ResultRecorder;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Alerting\Senders\SenderTestCase;

class DeliveryTest extends SenderTestCase
{
    use RefreshDatabase;

    private const SECRET_URL = 'https://hooks.slack.test/services/T000/B000/TOPSECRETVALUE';

    private CarbonImmutable $t0;

    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t0 = CarbonImmutable::parse('2026-11-06 10:00:00', 'UTC');
        CarbonImmutable::setTestNow($this->t0);
        $this->ws = Workspace::factory()->create(['name' => 'Acme Ops']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_successful_delivery_marks_sent_and_channel_health(): void
    {
        $channel = $this->slack();
        $delivery = $this->delivery($channel);
        $this->mockHttp([new Response(200, [], 'ok')]);

        $job = $this->runJob($delivery);

        $job->assertNotReleased();
        $job->assertNotFailed();
        $fresh = $this->reload($delivery);
        $this->assertSame(DeliveryStatus::Sent, $fresh->status);
        $this->assertSame(1, $fresh->attempts);
        $this->assertNull($fresh->last_error);
        $this->assertTrue($this->t0->equalTo($fresh->sent_at ?? $this->t0->subYear()));
        $health = $this->reloadChannel($channel);
        $this->assertTrue($this->t0->equalTo($health->last_success_at ?? $this->t0->subYear()));
        $this->assertNull($health->last_failure_at);

        $this->assertCount(1, $this->history);
        $this->assertSame(self::SECRET_URL, (string) $this->lastRequest()->getUri());
        $this->assertStringContainsString('Web shop', $this->lastJsonText('text'));

        // A duplicate job for a finished delivery sends nothing.
        $this->runJob($delivery)->assertNotReleased();
        $this->assertCount(1, $this->history);
        $this->assertSame(1, $this->reload($delivery)->attempts);
    }

    public function test_retryable_and_permanent_failures(): void
    {
        $channel = $this->slack();

        // 503: released with the backoff of the attempt, the delivery stays queued.
        $delivery = $this->delivery($channel);
        $this->mockHttp([new Response(503, [], 'upstream '.self::SECRET_URL)]);
        $this->runJob($delivery, 1)->assertReleased(10);
        $fresh = $this->reload($delivery);
        $this->assertSame(DeliveryStatus::Queued, $fresh->status);
        $this->assertSame(1, $fresh->attempts);
        $this->assertSame('HTTP 503', $fresh->last_error);
        $this->assertNull($this->reloadChannel($channel)->last_failure_at, 'a retry is not a channel failure yet');

        $this->mockHttp([new Response(503)]);
        $this->runJob($delivery, 2)->assertReleased(30);
        $this->mockHttp([new Response(503)]);
        $this->runJob($delivery, 3)->assertReleased(120);
        $this->mockHttp([new Response(503)]);
        $this->runJob($delivery, 4)->assertReleased(300);
        $this->assertSame(4, $this->reload($delivery)->attempts);

        // The fifth attempt is the last one.
        CarbonImmutable::setTestNow($this->t0->addMinutes(10));
        $this->mockHttp([new Response(503)]);
        $job = $this->runJob($delivery, 5);
        $job->assertNotReleased();
        $fresh = $this->reload($delivery);
        $this->assertSame(DeliveryStatus::Failed, $fresh->status);
        $this->assertSame(5, $fresh->attempts);
        $this->assertSame('HTTP 503', $fresh->last_error);
        $this->assertNull($fresh->sent_at);
        $health = $this->reloadChannel($channel);
        $this->assertTrue($this->t0->addMinutes(10)->equalTo($health->last_failure_at ?? $this->t0));
        $this->assertSame('HTTP 503', $health->last_error);

        // 404: permanent, failed at once.
        $delivery = $this->delivery($channel);
        $this->mockHttp([new Response(404)]);
        $this->runJob($delivery, 1)->assertNotReleased();
        $this->assertSame(DeliveryStatus::Failed, $this->reload($delivery)->status);
        $this->assertSame('HTTP 404', $this->reload($delivery)->last_error);

        // 429 with Retry-After: the provider's delay wins over the backoff.
        $delivery = $this->delivery($channel);
        $this->mockHttp([new Response(429, ['Retry-After' => '40'])]);
        $this->runJob($delivery, 1)->assertReleased(40);
        $this->assertSame(DeliveryStatus::Queued, $this->reload($delivery)->status);

        // A provider detail echoing the secret URL is redacted before it is stored.
        $delivery = $this->delivery($channel);
        $this->fakeSender(new DeliveryFailure('HTTP 400', permanent: true, detail: 'invalid_url '.self::SECRET_URL));
        $this->runJob($delivery, 1)->assertNotReleased();
        $error = $this->reload($delivery)->last_error ?? '';
        $this->assertStringStartsWith('HTTP 400: invalid_url', $error);
        $this->assertStringNotContainsString('TOPSECRETVALUE', $error);
        $this->assertStringNotContainsString('TOPSECRETVALUE', $this->reloadChannel($channel)->last_error ?? '');

        // Any other exception is retried and stored by class name only.
        $delivery = $this->delivery($channel);
        $this->fakeSender(new RuntimeException('boom '.self::SECRET_URL));
        $this->runJob($delivery, 1)->assertReleased(10);
        $this->assertSame('Unexpected error: RuntimeException', $this->reload($delivery)->last_error);
        $this->runJob($delivery, 5)->assertNotReleased();
        $this->assertSame(DeliveryStatus::Failed, $this->reload($delivery)->status);
        $this->assertSame('Unexpected error: RuntimeException', $this->reload($delivery)->last_error);

        // A job the queue gives up on (a timeout on the last attempt) does not leave the delivery queued.
        $delivery = $this->delivery($channel);
        (new SendNotification($delivery->id))->failed(new TimeoutExceededException('SendNotification has timed out.'));
        $this->assertSame(DeliveryStatus::Failed, $this->reload($delivery)->status);
        $this->assertSame('Unexpected error: TimeoutExceededException', $this->reload($delivery)->last_error);
    }

    public function test_mass_outage_sends_one_digest_per_channel(): void
    {
        $slack = $this->slack();
        $pagerduty = NotificationChannel::factory()->for($this->ws)->pagerduty()->create();
        $monitors = Monitor::factory()->for($this->ws)->count(1000)->create(['retries' => 0]);
        foreach (array_chunk($monitors->pluck('id')->all(), 250) as $ids) {
            $rows = [];
            foreach ($ids as $id) {
                $rows[] = ['monitor_id' => $id, 'notification_channel_id' => $slack->id];
                $rows[] = ['monitor_id' => $id, 'notification_channel_id' => $pagerduty->id];
            }
            DB::table('monitor_notification')->insert($rows);
        }
        $specs = $monitors->map(fn (Monitor $m): MonitorSpec => MonitorSpec::fromModel($m))->all();
        $recorder = $this->app->make(ResultRecorder::class);

        // Everything is up first; that is not alerted.
        $recorder->record(array_values(array_map(fn (MonitorSpec $s): array => [$s, CheckOutcome::up(20), $this->t0, null], $specs)));
        $this->assertSame(0, AlertDelivery::count());

        config(['queue.default' => 'database']);
        DB::table('jobs')->delete();
        $this->mockHttp(array_fill(0, 1005, new Response(202, [], '{}')));
        $at = $this->t0->addMinute();
        CarbonImmutable::setTestNow($at);
        $recorder->record(array_values(array_map(fn (MonitorSpec $s): array => [$s, CheckOutcome::down('refused'), $at, null], $specs)));

        // The recorder only queued evaluations: no alert, no send yet.
        $this->assertSame(5, DB::table('jobs')->where('queue', 'default')->where('payload', 'like', '%EvaluateAlerts%')->count());
        $this->assertSame(0, AlertEvent::count());
        $this->assertCount(0, $this->history);

        $this->assertSame(0, $this->workQueue('default'));
        $this->assertSame(1000, AlertEvent::where('kind', AlertKind::Down->value)->count());
        $this->assertSame(1005, DB::table('jobs')->where('queue', 'notifications')->count());
        $this->assertSame(0, $this->workQueue('notifications'));
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());

        $slackDeliveries = AlertDelivery::where('notification_channel_id', $slack->id)->get();
        $this->assertCount(5, $slackDeliveries);
        foreach ($slackDeliveries as $delivery) {
            $this->assertSame(DeliveryStatus::Sent, $delivery->status);
            $this->assertCount(200, $delivery->alert_event_ids);
        }
        $this->assertSame(1000, AlertDelivery::where('notification_channel_id', $pagerduty->id)->where('status', 'sent')->count());

        $byHost = ['hooks.slack.test' => [], 'events.pagerduty.com' => []];
        foreach ($this->history as $request) {
            $byHost[$request->getUri()->getHost()][] = $request;
        }
        $this->assertCount(5, $byHost['hooks.slack.test']);
        $this->assertCount(1000, $byHost['events.pagerduty.com']);
        foreach ($byHost['hooks.slack.test'] as $request) {
            $body = json_decode((string) $request->getBody(), true);
            $this->assertIsArray($body);
            $this->assertIsString($body['text'] ?? null);
            $this->assertStringContainsString('200 alerts in Acme Ops', $body['text']);
            $this->assertStringContainsString('and 180 more', $body['text']);
        }
    }

    public function test_deleted_channel_fails_delivery_without_retry(): void
    {
        $channel = $this->slack();
        $delivery = $this->delivery($channel);
        $channel->delete();
        $this->mockHttp([]);

        $this->runJob($delivery, 1)->assertNotReleased();
        $fresh = $this->reload($delivery);
        $this->assertSame(DeliveryStatus::Failed, $fresh->status);
        $this->assertSame('Channel removed', $fresh->last_error);
        $this->assertNull($fresh->notification_channel_id);

        $disabled = $this->slack(['active' => false]);
        $delivery = $this->delivery($disabled);
        $this->runJob($delivery, 1)->assertNotReleased();
        $this->assertSame(DeliveryStatus::Failed, $this->reload($delivery)->status);
        $this->assertSame('Channel disabled', $this->reload($delivery)->last_error);
        $this->assertNull($this->reloadChannel($disabled)->last_failure_at, 'a disabled channel is not a provider failure');

        $this->assertCount(0, $this->history);
    }

    public function test_missing_delivery_is_retried_briefly_then_dropped(): void
    {
        $log = Log::spy();
        $this->mockHttp([]);

        // Not visible yet (a queue outside the database) or already pruned: looked up again shortly, a bounded
        // number of times.
        $this->runJob(424242, 1)->assertReleased(5);
        $this->runJob(424242, 2)->assertReleased(5);
        $job = $this->runJob(424242, 3);
        $job->assertNotReleased();
        $job->assertNotFailed();

        $log->shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => str_contains($message, 'not found') && ($context['delivery_id'] ?? null) === 424242,
        );
        $this->assertCount(0, $this->history);
    }

    public function test_a_concurrent_claim_stops_the_duplicate_send(): void
    {
        $delivery = $this->delivery($this->slack());
        $this->mockHttp([new Response(200, [], 'ok')]);

        // Another job claims the delivery after this one has read it and before it claims it.
        $bumped = false;
        DB::listen(function (QueryExecuted $query) use (&$bumped, $delivery): void {
            if (! $bumped && preg_match('/from [`"]alert_events[`"]/i', $query->sql) === 1) {
                $bumped = true;
                DB::table('alert_deliveries')->where('id', $delivery->id)->increment('attempts');
            }
        });

        $job = $this->runJob($delivery);

        $this->assertTrue($bumped);
        $job->assertNotReleased();
        $job->assertNotFailed();
        $this->assertCount(0, $this->history, 'only the job that won the claim sends');
        $fresh = $this->reload($delivery);
        $this->assertSame(DeliveryStatus::Queued, $fresh->status);
        $this->assertSame(1, $fresh->attempts);
    }

    public function test_abandoned_delivery_records_channel_health(): void
    {
        $channel = $this->slack();
        $delivery = $this->delivery($channel);
        CarbonImmutable::setTestNow($this->t0->addMinute());

        (new SendNotification($delivery->id))->failed(new TimeoutExceededException('SendNotification has timed out.'));

        $this->assertSame(DeliveryStatus::Failed, $this->reload($delivery)->status);
        $health = $this->reloadChannel($channel);
        $this->assertTrue($this->t0->addMinute()->equalTo($health->last_failure_at ?? $this->t0));
        $this->assertSame('Unexpected error: TimeoutExceededException', $health->last_error);

        // A delivery that already finished is left alone, and so is its channel.
        CarbonImmutable::setTestNow($this->t0->addMinutes(5));
        (new SendNotification($delivery->id))->failed(new RuntimeException('late'));
        $this->assertSame('Unexpected error: TimeoutExceededException', $this->reload($delivery)->last_error);
        $this->assertTrue($this->t0->addMinute()->equalTo($this->reloadChannel($channel)->last_failure_at ?? $this->t0));
    }

    public function test_orphaned_queued_delivery_is_requeued_or_abandoned(): void
    {
        Queue::fake([SendNotification::class]);
        $channel = $this->slack();
        $orphan = $this->delivery($channel);
        $fresh = $this->delivery($channel);
        Artisan::call('intrawatch:alerts:tick');
        Queue::assertNothingPushed();

        // The job of the first delivery was lost; the second one was queued moments ago.
        AlertDelivery::query()->whereKey($orphan->id)->toBase()->update(['updated_at' => $this->t0->subHours(2)]);
        $this->assertSame(0, Artisan::call('intrawatch:alerts:tick'));

        $resolved = $this->reload($orphan)->status !== DeliveryStatus::Queued;
        $requeued = Queue::pushed(SendNotification::class, fn (SendNotification $job): bool => $job->deliveryId === $orphan->id)->count() === 1;
        $this->assertTrue($resolved || $requeued, 'an orphaned delivery is neither left queued without a job nor ignored');
        $this->assertSame(0, Queue::pushed(SendNotification::class, fn (SendNotification $job): bool => $job->deliveryId === $fresh->id)->count());
        $this->assertSame(DeliveryStatus::Queued, $this->reload($fresh)->status);

        // Re-queueing is not repeated on every tick.
        $this->assertSame(0, Artisan::call('intrawatch:alerts:tick'));
        $this->assertLessThanOrEqual(1, Queue::pushed(SendNotification::class)->count());
    }

    public function test_orphaned_delivery_with_attempts_used_up_is_failed(): void
    {
        Queue::fake([SendNotification::class]);
        $channel = $this->slack();
        $delivery = $this->delivery($channel);
        AlertDelivery::query()->whereKey($delivery->id)->toBase()->update(['attempts' => DeliveryRunner::tries(), 'updated_at' => $this->t0->subHours(2)]);

        $this->assertSame(0, Artisan::call('intrawatch:alerts:tick'));

        Queue::assertNothingPushed();
        $this->assertSame(DeliveryStatus::Failed, $this->reload($delivery)->status);
        $this->assertNotNull($this->reloadChannel($channel)->last_failure_at);
    }

    public function test_backoff_setting_accepts_whole_seconds_as_strings_and_rejects_anything_else(): void
    {
        config(['alerting.send.backoff_seconds' => ['10', 30, ' 120 ', '300']]);
        $this->assertSame([10, 30, 120, 300], (new SendNotification(1))->backoff());

        foreach ([['10', 'soon'], [10, -5], [10, 2.5], [], 'often'] as $invalid) {
            config(['alerting.send.backoff_seconds' => $invalid]);
            try {
                DeliveryRunner::backoffs();
                $this->fail('accepted '.json_encode($invalid));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('alerting.send.backoff_seconds', $e->getMessage());
            }
        }
    }

    public function test_queue_payload_holds_no_secrets(): void
    {
        config(['queue.default' => 'database']);
        $channel = $this->slack();
        $delivery = $this->delivery($channel);

        SendNotification::dispatch($delivery->id);

        $job = DB::table('jobs')->sole();
        $this->assertSame('notifications', $job->queue);
        $this->assertIsString($job->payload);
        foreach (['TOPSECRETVALUE', 'hooks.slack', 'Web shop', 'Connection refused', 'Acme Ops'] as $needle) {
            $this->assertStringNotContainsString($needle, $job->payload);
        }
        $decoded = json_decode($job->payload, true);
        $this->assertIsArray($decoded);
        $this->assertSame(5, $decoded['maxTries'] ?? null);
        $this->assertSame(30, $decoded['timeout'] ?? null);
        $this->assertTrue($decoded['failOnTimeout'] ?? null);
        $this->assertSame('10,30,120,300', $decoded['backoff'] ?? null);
        $this->assertIsArray($decoded['data'] ?? null);
        $this->assertIsString($decoded['data']['command'] ?? null);
        $command = unserialize($decoded['data']['command']);
        $this->assertInstanceOf(SendNotification::class, $command);
        $this->assertSame($delivery->id, $command->deliveryId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function slack(array $attributes = []): NotificationChannel
    {
        return NotificationChannel::factory()->for($this->ws)->create([
            'secrets' => ['webhook_url' => self::SECRET_URL],
            ...$attributes,
        ]);
    }

    private function delivery(NotificationChannel $channel): AlertDelivery
    {
        $monitor = Monitor::factory()->for($this->ws)->create(['name' => 'Web shop']);
        $event = AlertEvent::create([
            'workspace_id' => $this->ws->id,
            'monitor_id' => $monitor->id,
            'kind' => AlertKind::Down,
            'status' => MonitorStatus::Down,
            'previous_status' => MonitorStatus::Up,
            'message' => 'Connection refused',
            'created_at' => $this->t0,
        ]);

        return AlertDelivery::create([
            'workspace_id' => $this->ws->id,
            'notification_channel_id' => $channel->id,
            'alert_event_ids' => [$event->id],
            'status' => DeliveryStatus::Queued,
        ]);
    }

    private function reload(AlertDelivery $delivery): AlertDelivery
    {
        return AlertDelivery::query()->findOrFail($delivery->id);
    }

    private function reloadChannel(NotificationChannel $channel): NotificationChannel
    {
        return NotificationChannel::query()->findOrFail($channel->id);
    }

    /**
     * @param  list<Response>  $responses
     */
    private function mockHttp(array $responses): void
    {
        $this->app->instance(OutboundHttp::class, $this->http($responses));
    }

    private function fakeSender(\Throwable $error): void
    {
        $this->app->bind(SlackSender::class, fn (): ChannelSender => new class($error) implements ChannelSender
        {
            public function __construct(private readonly \Throwable $error) {}

            public function send(ChannelSpec $channel, OutgoingNotification $n, string $idempotencyKey): void
            {
                throw $this->error;
            }
        });
    }

    private function runJob(AlertDelivery|int $delivery, int $attempt = 1): SendNotification
    {
        $job = (new SendNotification($delivery instanceof AlertDelivery ? $delivery->id : $delivery))->withFakeQueueInteractions();
        $this->assertInstanceOf(FakeJob::class, $job->job);
        $job->job->attempts = $attempt;
        $job->handle($this->app->make(DeliveryRunner::class));

        return $job;
    }
}
