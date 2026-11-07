<?php

namespace Tests\Feature\Monitoring;

use App\Events\ChecksRecorded;
use App\Events\MonitorStatusChanged;
use App\Jobs\BroadcastEvents;
use App\Models\Monitor;
use App\Models\Workspace;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Engine\ResultRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BroadcastBatchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{0: MonitorSpec, 1: CheckOutcome, 2: CarbonImmutable, 3: ?int}>
     */
    private function failures(int $count): array
    {
        return array_values(Monitor::factory()->count($count)->for(Workspace::factory())->http()->create(['retries' => 0])
            ->map(fn (Monitor $monitor): array => [MonitorSpec::fromModel($monitor), CheckOutcome::down('boom'), CarbonImmutable::now(), null])
            ->all());
    }

    public function test_a_recorded_batch_queues_one_job_for_all_its_broadcasts(): void
    {
        Queue::fake();

        $this->app->make(ResultRecorder::class)->record($this->failures(5));

        Queue::assertNotPushed(BroadcastEvent::class);
        Queue::assertPushed(BroadcastEvents::class, 1);
        Queue::assertPushed(BroadcastEvents::class, function (BroadcastEvents $job): bool {
            $status = array_filter($job->events, fn (object $event): bool => $event instanceof MonitorStatusChanged);
            $checks = array_filter($job->events, fn (object $event): bool => $event instanceof ChecksRecorded);

            return count($status) === 5 && count($checks) === 1;
        });
    }

    public function test_the_job_broadcasts_every_event_and_listeners_still_run(): void
    {
        config(['broadcasting.default' => 'log']);
        $broadcasts = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$broadcasts): void {
            if (str_starts_with($e->message, 'Broadcasting [')) {
                $broadcasts[] = $e->message;
            }
        });
        $heard = 0;
        Event::listen(MonitorStatusChanged::class, function () use (&$heard): void {
            $heard++;
        });

        $this->app->make(ResultRecorder::class)->record($this->failures(3));

        $this->assertSame(3, $heard);
        $this->assertCount(4, $broadcasts);
        $this->assertCount(3, array_filter($broadcasts, fn (string $line): bool => str_contains($line, '[monitor.status_changed]')));
    }
}
