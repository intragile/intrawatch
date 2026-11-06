<?php

namespace Tests\Feature\Monitoring;

use App\Enums\MonitorStatus;
use App\Events\ChecksRecorded;
use App\Events\MonitorChanged;
use App\Events\MonitorStatusChanged;
use App\Models\CheckResult;
use App\Models\Monitor;
use App\Models\MonitorEvent;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Engine\NoMaintenance;
use App\Monitoring\Engine\NullGroupEvaluator;
use App\Monitoring\Engine\ResultRecorder;
use App\Monitoring\Engine\StatusMachine;
use App\Monitoring\MonitorActivation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ResultRecorderTest extends TestCase
{
    use RefreshDatabase;

    private function recorder(): ResultRecorder
    {
        return $this->app->make(ResultRecorder::class);
    }

    private function record(Monitor $monitor, CheckOutcome $outcome, ?CarbonImmutable $at = null, ?ResultRecorder $recorder = null): void
    {
        ($recorder ?? $this->recorder())->record([[MonitorSpec::fromModel($monitor->fresh() ?? $monitor), $outcome, $at ?? CarbonImmutable::now(), 12]]);
    }

    public function test_failure_count_survives_restart(): void
    {
        $monitor = Monitor::factory()->create(['retries' => 2]);

        $this->record($monitor, CheckOutcome::down('Connection refused'));
        $this->assertSame(MonitorStatus::Pending, $monitor->state()->firstOrFail()->status);
        $this->assertSame(1, $monitor->state()->firstOrFail()->consecutive_failures);

        // Fresh instances stand in for a restarted worker: nothing survives but the database.
        $restarted = new ResultRecorder(new StatusMachine, new NoMaintenance, new NullGroupEvaluator);

        $this->record($monitor, CheckOutcome::down('Connection refused'), recorder: $restarted);
        $this->assertSame(MonitorStatus::Pending, $monitor->state()->firstOrFail()->status);

        $this->record($monitor, CheckOutcome::down('Connection refused'), recorder: $restarted);
        $state = $monitor->state()->firstOrFail();
        $this->assertSame(MonitorStatus::Down, $state->status);
        $this->assertSame(3, $state->consecutive_failures);

        $this->assertSame(3, CheckResult::where('monitor_id', $monitor->id)->count());
        $events = MonitorEvent::where('monitor_id', $monitor->id)->get();
        $this->assertCount(1, $events);
        $event = $events->firstOrFail();
        $this->assertSame(MonitorEvent::KIND_STATUS_CHANGED, $event->kind);
        $this->assertSame(MonitorStatus::Pending, $event->from_status);
        $this->assertSame(MonitorStatus::Down, $event->to_status);
        $this->assertSame('Connection refused', $event->message);
    }

    public function test_recorder_skips_deleted_and_paused_monitors(): void
    {
        Event::fake();
        $deleted = Monitor::factory()->create();
        $paused = Monitor::factory()->create();
        $live = Monitor::factory()->create();

        $deletedSpec = MonitorSpec::fromModel($deleted);
        $pausedSpec = MonitorSpec::fromModel($paused);
        $deleted->delete();
        MonitorActivation::apply($paused, false);

        $batch = $this->recorder()->record([
            [$deletedSpec, CheckOutcome::down('500'), CarbonImmutable::now(), null],
            [$pausedSpec, CheckOutcome::down('500'), CarbonImmutable::now(), null],
            [MonitorSpec::fromModel($live), CheckOutcome::up(40), CarbonImmutable::now(), 3],
        ]);

        $this->assertSame([$live->id], CheckResult::pluck('monitor_id')->all());
        $this->assertSame([$live->id], array_keys($batch->transitions));
        $this->assertSame(MonitorStatus::Paused, $paused->state()->firstOrFail()->status);
        $this->assertSame(0, MonitorEvent::whereIn('monitor_id', [$deleted->id, $paused->id])->count());
        Event::assertDispatched(MonitorStatusChanged::class, fn (MonitorStatusChanged $e) => $e->monitorId === $live->id);
        Event::assertDispatchedTimes(MonitorStatusChanged::class, 1);
    }

    public function test_watch_change_creates_event_but_first_value_is_silent(): void
    {
        Event::fake();
        $monitor = Monitor::factory()->dns()->create();

        $this->record($monitor, CheckOutcome::up(5, watch: ['dns.records' => 'A:1.1.1.1']));
        $this->record($monitor, CheckOutcome::up(5, watch: ['dns.records' => 'A:1.1.1.1']));
        $this->assertSame(0, MonitorEvent::where('kind', MonitorEvent::KIND_CHANGED)->count());
        Event::assertNotDispatched(MonitorChanged::class);

        $this->record($monitor, CheckOutcome::up(5, watch: ['dns.records' => 'A:2.2.2.2']));
        $events = MonitorEvent::where('kind', MonitorEvent::KIND_CHANGED)->get();
        $this->assertCount(1, $events);
        $event = $events->firstOrFail();
        $this->assertSame('dns.records', $event->key);
        $this->assertSame('A:1.1.1.1', $event->old_value);
        $this->assertSame('A:2.2.2.2', $event->new_value);
        $this->assertNull($event->from_status);
        $this->assertSame(['dns.records' => 'A:2.2.2.2'], $monitor->state()->firstOrFail()->watch);

        Event::assertDispatched(MonitorChanged::class, fn (MonitorChanged $e) => $e->monitorId === $monitor->id
            && $e->workspaceId === $monitor->workspace_id
            && $e->key === 'dns.records' && $e->old === 'A:1.1.1.1' && $e->new === 'A:2.2.2.2');
        Event::assertDispatchedTimes(MonitorChanged::class, 1);
    }

    public function test_status_change_dispatches_after_commit(): void
    {
        Event::fake();
        $monitor = Monitor::factory()->create(['retries' => 0]);
        $this->record($monitor, CheckOutcome::up(30));
        Event::assertDispatchedTimes(MonitorStatusChanged::class, 1);

        $dispatchedInsideTransaction = false;
        Event::listen(MonitorStatusChanged::class, function () use (&$dispatchedInsideTransaction): void {
            $dispatchedInsideTransaction = DB::transactionLevel() > 1;
        });

        $at = CarbonImmutable::now()->addMinute()->startOfSecond();
        $this->record($monitor, CheckOutcome::down('HTTP 503'), $at);

        Event::assertDispatchedTimes(MonitorStatusChanged::class, 2);
        Event::assertDispatched(MonitorStatusChanged::class, fn (MonitorStatusChanged $e) => $e->from === MonitorStatus::Up
            && $e->to === MonitorStatus::Down
            && $e->message === 'HTTP 503'
            && $e->at->equalTo($at)
            && $e->workspaceId === $monitor->workspace_id);
        Event::assertDispatched(ChecksRecorded::class, fn (ChecksRecorded $e) => $e->checks[0]['status'] === 'down'
            && $e->checks[0]['monitor_id'] === $monitor->id);
        $this->assertFalse($dispatchedInsideTransaction);

        $state = $monitor->state()->firstOrFail();
        $this->assertSame(MonitorStatus::Down, $state->status);
        $this->assertTrue($state->status_since->equalTo($at));
        $this->assertSame('HTTP 503', $state->last_message);
    }

    public function test_results_carry_drift_bucket_and_facts(): void
    {
        $monitor = Monitor::factory()->create();
        $at = CarbonImmutable::parse('2026-11-06 10:15:42', 'UTC');

        $batch = $this->recorder()->record([
            [MonitorSpec::fromModel($monitor), CheckOutcome::up(77, facts: ['http.status' => 200]), $at, 250],
            [MonitorSpec::fromModel($monitor), CheckOutcome::down('HTTP 500'), $at->addSeconds(30), 10],
        ]);

        [$first, $second] = CheckResult::where('monitor_id', $monitor->id)->orderBy('id')->get()->all();
        $this->assertSame(MonitorStatus::Up, $first->status);
        $this->assertSame(77, $first->latency_ms);
        $this->assertSame(250, $first->drift_ms);
        $this->assertSame(intdiv($at->getTimestamp(), 60) * 60, $first->bucket_minute);
        $this->assertSame(['http.status' => 200], $first->facts);
        $this->assertTrue($first->checked_at->equalTo($at));
        $this->assertSame(MonitorStatus::Pending, $second->status);
        $this->assertSame('HTTP 500', $second->message);
        $this->assertNull($second->facts);

        // Items of one monitor apply in order, so the batch ends in the second outcome's state.
        $this->assertSame(MonitorStatus::Pending, $batch->transitions[$monitor->id]->effective);
        $this->assertSame(1, $monitor->state()->firstOrFail()->consecutive_failures);
    }
}
