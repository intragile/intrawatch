<?php

namespace Tests\Feature\Monitoring;

use Amp\DeferredCancellation;
use Amp\Future;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\WorkspaceRole;
use App\Events\ChecksRecorded;
use App\Models\CheckResult;
use App\Models\Monitor;
use App\Models\User;
use App\Models\Workspace;
use App\Monitoring\Checks\CheckerRegistry;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\DatabaseGroupEvaluator;
use App\Monitoring\Engine\MaintenanceOracle;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Engine\NullDeadlineEvaluator;
use App\Monitoring\Engine\NullGroupEvaluator;
use App\Monitoring\Engine\ResultRecorder;
use App\Monitoring\Engine\StatusMachine;
use App\Monitoring\Engine\Transition;
use App\Monitoring\Worker\Heartbeat;
use App\Monitoring\Worker\MonitorLoader;
use App\Monitoring\Worker\OutcomeBuffer;
use App\Monitoring\Worker\Scheduler;
use App\Status\Maintenance\CalendarMaintenanceOracle;
use App\Status\Maintenance\MaintenanceCalendarLoader;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDOException;
use RuntimeException;
use Tests\Support\FailureBudget;
use Tests\Support\FakeChecker;
use Tests\Support\FakeClock;
use Tests\Support\PoisonMaintenance;
use Tests\TestCase;

use function Amp\async;

class WorkerTest extends TestCase
{
    use RefreshDatabase;

    private FakeClock $clock;

    private FakeChecker $checker;

    private Workspace $ws;

    private int $reconnects = 0;

    /** @var list<MessageLogged> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FakeClock;
        $this->checker = new FakeChecker($this->clock);
        $this->app->instance(FakeChecker::class, $this->checker);
        $this->app->make(CheckerRegistry::class)->register(MonitorType::Tcp, FakeChecker::class);
        $this->ws = Workspace::factory()->create();
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logs[] = $e;
        });
    }

    public function test_due_monitors_run_and_reschedule_on_interval(): void
    {
        $monitors = $this->tcpMonitors(3, ['interval_seconds' => 20, 'retry_interval_seconds' => 20]);

        [$stop, $run] = $this->start($this->scheduler());
        $this->clock->advance(65);
        $this->stop($stop, $run);

        foreach ($monitors as $monitor) {
            $times = $this->resultTimes($monitor);
            $this->assertContains(count($times), [3, 4], "monitor {$monitor->id}");
            for ($i = 1; $i < count($times); $i++) {
                $this->assertGreaterThanOrEqual(20, $times[$i - 1]->diffInSeconds($times[$i]));
            }
            $this->assertCount(count($times), $this->checker->callsFor($monitor->id));
        }
    }

    public function test_only_own_shard_is_checked(): void
    {
        $monitors = $this->tcpMonitors(10, ['interval_seconds' => 20, 'retry_interval_seconds' => 20]);

        [$stop, $run] = $this->start($this->scheduler(shard: 1, shards: 3));
        $this->clock->advance(25);
        $this->stop($stop, $run);

        foreach ($monitors as $monitor) {
            $count = CheckResult::where('monitor_id', $monitor->id)->count();
            if ($monitor->id % 3 === 1) {
                $this->assertGreaterThan(0, $count, "monitor {$monitor->id} belongs to the shard");
            } else {
                $this->assertSame(0, $count, "monitor {$monitor->id} belongs to another shard");
                $this->assertSame([], $this->checker->callsFor($monitor->id));
            }
        }
    }

    public function test_a_failing_maintenance_refresh_is_logged_and_the_poll_loop_keeps_running(): void
    {
        $oracle = new class implements MaintenanceOracle
        {
            public int $warms = 0;

            public function underMaintenance(array $monitorIds, CarbonImmutable $at): array
            {
                return [];
            }

            public function warm(bool $force = false): void
            {
                $this->warms++;

                throw new RuntimeException('calendar unavailable');
            }
        };
        $this->tcpMonitors(1, ['interval_seconds' => 60, 'retry_interval_seconds' => 60]);

        [$stop, $run] = $this->start($this->scheduler(maintenance: $oracle));
        $this->clock->advance(16);
        $added = $this->tcpMonitors(1, ['interval_seconds' => 60, 'retry_interval_seconds' => 60])[0];
        $this->clock->advance(70);
        $this->stop($stop, $run);

        $this->assertGreaterThanOrEqual(10, $oracle->warms, 'the loop warms on every poll after failures');
        $logged = array_filter($this->logs, fn (MessageLogged $e): bool => str_contains($e->message, 'Refreshing the maintenance calendar failed'));
        $this->assertNotEmpty($logged);
        $this->assertNotEmpty($this->checker->callsFor($added->id), 'polling still applies new monitors');
    }

    public function test_a_check_requested_by_a_new_window_is_recorded_with_the_new_calendar(): void
    {
        // The worker's own calendar, throttled so that it makes no stamp check of its own during the test.
        $oracle = new CalendarMaintenanceOracle(MaintenanceCalendarLoader::fromConfig(), $this->clock, 600, 1200);
        $recorder = new ResultRecorder(new StatusMachine, $oracle, new DatabaseGroupEvaluator($oracle));
        $buffer = new OutcomeBuffer(recorder: $recorder, clock: $this->clock, capacity: 10_000, flushSeconds: 1.0, reconnect: function (): void {});
        // Pinned ids: the leaf (id 2) first falls due 37 s in, so exactly one check runs in the first 65 s.
        $group = Monitor::factory()->for($this->ws)->group('all')->create(['id' => 1]);
        $leaf = Monitor::factory()->for($this->ws)->tcp('example.test', 443)->create(['id' => 2, 'parent_id' => $group->id, 'interval_seconds' => 60, 'retry_interval_seconds' => 60]);
        $editor = User::factory()->memberOf($this->ws, WorkspaceRole::Editor)->create();

        [$stop, $run] = $this->start($this->scheduler(buffer: $buffer, maintenance: $oracle));
        $this->clock->advance(65);
        $this->assertCount(1, $this->checker->callsFor($leaf->id));
        $this->assertSame(MonitorStatus::Up, $group->state()->firstOrFail()->status);

        $now = $this->clock->now();
        $this->actingAs($editor)->postJson("/api/v1/workspaces/{$this->ws->slug}/maintenances", [
            'title' => 'Now', 'strategy' => 'once', 'monitor_ids' => [$group->id],
            'starts_at' => $now->subMinute()->toIso8601ZuluString(), 'ends_at' => $now->addHour()->toIso8601ZuluString(),
        ])->assertCreated();
        $this->assertSame(MonitorStatus::Maintenance, $group->state()->firstOrFail()->status);

        // The next poll sees the requested check; it must be recorded against the calendar that has the window.
        $this->clock->advance(10);
        $this->stop($stop, $run);

        $this->assertCount(2, $this->checker->callsFor($leaf->id), 'the requested check ran');
        $this->assertSame(MonitorStatus::Maintenance, CheckResult::where('monitor_id', $leaf->id)->orderByDesc('id')->firstOrFail()->status);
        $this->assertSame(MonitorStatus::Maintenance, $group->state()->firstOrFail()->status, 'the group the API muted stays muted');
    }

    public function test_monitor_changes_apply_without_restart(): void
    {
        $editor = User::factory()->memberOf($this->ws, WorkspaceRole::Editor)->create();
        // Pinned ids, so no monitor falls due at the very instant of the edits (see tcpMonitors).
        [$faster, $paused, $deleted, $requested, $inFlight] = $this->tcpMonitors(5, ['interval_seconds' => 60, 'retry_interval_seconds' => 60], firstId: 1);
        $this->checker->hold($inFlight->id);

        [$stop, $run] = $this->start($this->scheduler());
        $this->clock->advance(61);
        $this->assertCount(1, $this->checker->callsFor($inFlight->id));
        $editedAt = $this->clock->now();

        $url = "/api/v1/workspaces/{$this->ws->slug}/monitors";
        $this->actingAs($editor)->patchJson("{$url}/{$faster->id}", ['interval_seconds' => 20, 'retry_interval_seconds' => 20])->assertOk();
        $this->actingAs($editor)->patchJson("{$url}/{$paused->id}", ['active' => false])->assertOk();
        $this->actingAs($editor)->deleteJson("{$url}/{$deleted->id}")->assertNoContent();
        $this->actingAs($editor)->postJson("{$url}/{$requested->id}/check")->assertStatus(202);
        $this->actingAs($editor)->deleteJson("{$url}/{$inFlight->id}")->assertNoContent();

        // One poll interval later the worker has seen every change.
        $this->clock->advance(5);
        $polledAt = $this->clock->now();
        $this->checker->release($inFlight->id);
        $this->clock->advance(45);
        $this->stop($stop, $run);

        $fasterTimes = array_values(array_filter($this->resultTimes($faster), fn (CarbonImmutable $t): bool => $t->gt($editedAt)));
        $this->assertGreaterThanOrEqual(2, count($fasterTimes), 'the new 20 s interval is used');
        for ($i = 1; $i < count($fasterTimes); $i++) {
            $this->assertGreaterThanOrEqual(20, $fasterTimes[$i - 1]->diffInSeconds($fasterTimes[$i]));
            $this->assertLessThan(60, $fasterTimes[$i - 1]->diffInSeconds($fasterTimes[$i]));
        }

        foreach ([$paused, $deleted] as $gone) {
            $this->assertSame([], array_filter($this->resultTimes($gone), fn (CarbonImmutable $t): bool => $t->gte($editedAt)));
            $this->assertSame([], array_filter($this->checker->callsFor($gone->id), fn (CarbonImmutable $t): bool => $t->gt($polledAt)));
        }

        $this->assertSame(0, CheckResult::where('monitor_id', $inFlight->id)->count(), 'the released in-flight check is discarded');
        $this->assertCount(1, $this->checker->callsFor($inFlight->id));

        $requestedRuns = array_filter($this->resultTimes($requested), fn (CarbonImmutable $t): bool => $t->gte($editedAt) && $t->lte($polledAt->addSecond()));
        $this->assertNotEmpty($requestedRuns, 'a requested check runs right after the poll');
    }

    public function test_flush_failure_keeps_buffer_and_retries(): void
    {
        $monitors = $this->tcpMonitors(2, ['interval_seconds' => 20, 'retry_interval_seconds' => 20]);
        $this->failResultInserts(2);

        [$stop, $run] = $this->start($this->scheduler());
        $this->clock->advance(45);
        $this->stop($stop, $run);

        foreach ($monitors as $monitor) {
            $calls = count($this->checker->callsFor($monitor->id));
            $this->assertGreaterThan(0, $calls);
            $this->assertSame($calls, CheckResult::where('monitor_id', $monitor->id)->count(), 'every outcome is recorded exactly once');
        }
        $this->assertSame(2, $this->reconnects);
        $this->assertCount(2, array_filter($this->logs, fn (MessageLogged $e): bool => $e->level === 'warning' && str_contains($e->message, 'Flushing check outcomes failed')));
    }

    public function test_checks_continue_during_database_outage(): void
    {
        $monitors = $this->tcpMonitors(2, ['interval_seconds' => 20, 'retry_interval_seconds' => 20]);
        $outage = $this->failResultInserts(PHP_INT_MAX);

        [$stop, $run] = $this->start($this->scheduler());
        $this->clock->advance(180);
        $this->assertSame(0, CheckResult::count());
        foreach ($monitors as $monitor) {
            $this->assertGreaterThanOrEqual(8, count($this->checker->callsFor($monitor->id)), 'checks keep their schedule while nothing can be recorded');
        }

        $outage->remaining = 0;
        $this->clock->advance(35);
        $this->stop($stop, $run);

        foreach ($monitors as $monitor) {
            $times = $this->resultTimes($monitor);
            $this->assertCount(count($this->checker->callsFor($monitor->id)), $times, 'every outcome is recorded exactly once');
            for ($i = 1; $i < count($times); $i++) {
                $this->assertGreaterThanOrEqual(20, $times[$i - 1]->diffInSeconds($times[$i]));
            }
        }
    }

    public function test_outage_beyond_capacity_drops_oldest_and_keeps_checking(): void
    {
        $monitors = $this->tcpMonitors(2, ['interval_seconds' => 20, 'retry_interval_seconds' => 20]);
        $outage = $this->failResultInserts(PHP_INT_MAX);

        [$stop, $run] = $this->start($this->scheduler(buffer: $this->buffer(capacity: 10)));
        $this->clock->advance(180);
        $outage->remaining = 0;
        $this->clock->advance(35);
        $this->stop($stop, $run);

        $calls = count($this->checker->calls);
        $this->assertGreaterThanOrEqual(16, $calls);
        $dropped = 0;
        foreach ($this->logs as $log) {
            if (preg_match('/dropped (\d+) outcomes/', $log->message, $m) === 1) {
                $dropped += (int) $m[1];
            }
        }
        $this->assertGreaterThan(0, $dropped);
        $this->assertSame($calls - $dropped, CheckResult::count(), 'everything not dropped is recorded once');

        // The newest outcomes survive: each monitor's last check is among the recorded ones.
        foreach ($monitors as $monitor) {
            $calls = $this->checker->callsFor($monitor->id);
            $times = $this->resultTimes($monitor);
            $this->assertNotEmpty($times);
            $this->assertSame($calls[count($calls) - 1]->getTimestamp(), $times[count($times) - 1]->getTimestamp());
        }
    }

    public function test_an_outcome_the_recorder_rejects_is_isolated_and_dropped(): void
    {
        [$good, $poison, $other] = $this->tcpMonitors(3);
        $recorder = new ResultRecorder(new StatusMachine, new PoisonMaintenance($poison->id), new NullGroupEvaluator);
        $buffer = new OutcomeBuffer($recorder, $this->clock);

        $released = [];
        foreach ([$good, $poison, $other] as $monitor) {
            $buffer->push(MonitorSpec::fromModel($monitor), CheckOutcome::up(5), $this->clock->now(), 0, function (?Transition $t) use (&$released, $monitor): void {
                $released[$monitor->id] = $t;
            });
        }

        $this->assertFalse($buffer->flush());
        $this->assertFalse($buffer->flush());
        $this->assertSame(0, CheckResult::count(), 'non-database failures are retried a few times first');
        $this->assertTrue($buffer->flush(), 'the third failure isolates the bad outcome');

        $this->assertSame(0, $buffer->count());
        $this->assertSame(1, CheckResult::where('monitor_id', $good->id)->count());
        $this->assertSame(1, CheckResult::where('monitor_id', $other->id)->count());
        $this->assertSame(0, CheckResult::where('monitor_id', $poison->id)->count());
        $this->assertCount(3, $released);
        $this->assertNull($released[$poison->id]);

        $drops = array_values(array_filter($this->logs, fn (MessageLogged $e): bool => str_contains($e->message, 'Dropped a check outcome')));
        $this->assertCount(1, $drops);
        $this->assertSame('error', $drops[0]->level);
        $this->assertSame(['monitor_id' => $poison->id, 'exception' => RuntimeException::class], $drops[0]->context);
    }

    public function test_buffer_cap_drops_oldest(): void
    {
        $monitor = $this->tcpMonitors(1)[0];
        $spec = MonitorSpec::fromModel($monitor);
        $buffer = $this->buffer(capacity: 3);
        $failing = $this->failResultInserts(PHP_INT_MAX);

        /** @var list<array{0: int, 1: ?Transition}> $done */
        $done = [];
        $start = $this->clock->now();
        for ($i = 0; $i < 5; $i++) {
            $buffer->push($spec, CheckOutcome::up(5), $start->addSeconds($i * 20), 0, function (?Transition $t) use (&$done, $i): void {
                $done[] = [$i, $t];
            });
        }

        $this->assertSame(3, $buffer->count());
        $this->assertSame([[0, null], [1, null]], array_slice($done, 0), 'the two oldest outcomes are dropped');
        $this->assertFalse($buffer->flush());
        $this->assertNotEmpty(array_filter($this->logs, fn (MessageLogged $e): bool => str_contains($e->message, 'dropped 2 outcomes')));

        $failing->remaining = 0;
        $this->assertTrue($buffer->flush());
        $this->assertSame(0, $buffer->count());

        $recorded = array_map(fn (CarbonImmutable $t): int => (int) $start->diffInSeconds($t), $this->resultTimes($monitor));
        $this->assertSame([40, 60, 80], $recorded);
        // The failed flush already released the kept outcomes to the scheduler; recording them later does not
        // call back a second time.
        $this->assertSame([[0, null], [1, null], [2, null], [3, null], [4, null]], array_slice($done, 0));
    }

    public function test_check_broadcasts_are_throttled_per_workspace(): void
    {
        $dispatched = [];
        Event::listen(ChecksRecorded::class, function (ChecksRecorded $e) use (&$dispatched): void {
            $dispatched[] = $e;
        });
        [$first, $second] = $this->tcpMonitors(2);
        $buffer = $this->buffer();
        $push = fn (Monitor $m, CheckOutcome $o) => $buffer->push(MonitorSpec::fromModel($m), $o, $this->clock->now(), 0, function (): void {});

        $push($first, CheckOutcome::up(5));
        $buffer->flush();
        $buffer->broadcast(false);
        $this->assertCount(1, $dispatched, 'the first broadcast of a workspace goes out right away');

        $this->clock->advance(1);
        $push($first, CheckOutcome::up(7));
        $push($second, CheckOutcome::up(9));
        $buffer->flush();
        $buffer->broadcast(false);
        $this->clock->advance(1);
        $push($first, CheckOutcome::up(11));
        $buffer->flush();
        $buffer->broadcast(false);
        $this->assertCount(1, $dispatched, 'nothing more within five seconds');

        $this->clock->advance(3);
        $buffer->broadcast(false);
        $this->assertCount(2, $dispatched);
        $checks = array_column(array_slice($dispatched, 0)[1]->checks, 'latency_ms', 'monitor_id');
        $this->assertSame([$first->id => 11, $second->id => 9], $checks, 'only the latest check per monitor');
    }

    public function test_group_evaluations_ride_along_with_the_check_broadcast(): void
    {
        $dispatched = [];
        Event::listen(ChecksRecorded::class, function (ChecksRecorded $e) use (&$dispatched): void {
            $dispatched[] = $e;
        });
        $group = Monitor::factory()->for($this->ws)->group()->create();
        [$child] = $this->tcpMonitors(1, ['parent_id' => $group->id, 'retries' => 0]);
        $buffer = $this->buffer();

        $buffer->push(MonitorSpec::fromModel($child), CheckOutcome::down('refused'), $this->clock->now(), 0, function (): void {});
        $buffer->flush();
        $buffer->broadcast(true);

        $this->assertCount(1, $dispatched);
        $checks = array_column(array_slice($dispatched, 0)[0]->checks, null, 'monitor_id');
        $this->assertSame('down', $checks[$child->id]['status'] ?? null);
        $stored = CheckResult::where('monitor_id', $group->id)->sole()->checked_at;
        $this->assertSame(['monitor_id' => $group->id, 'status' => 'down', 'latency_ms' => null, 'checked_at' => $stored->toIso8601ZuluString()], $checks[$group->id] ?? null, 'the stamp of the stored group row');
    }

    public function test_status_command_reports_stale_shard(): void
    {
        config(['monitoring.worker.shards_total' => 2]);
        $now = CarbonImmutable::now();
        $row = fn (int $shard, CarbonImmutable $seen): array => [
            'shard' => $shard, 'shards' => 2, 'hostname' => 'worker-a', 'pid' => 100 + $shard, 'started_at' => $now->subHour(),
            'last_seen_at' => $seen, 'monitors' => 10, 'checks_last_minute' => 10, 'max_drift_ms_last_minute' => 5, 'buffered' => 0,
        ];
        DB::table('check_workers')->insert([$row(0, $now->subSeconds(5)), $row(1, $now->subSeconds(90))]);

        $this->assertSame(1, Artisan::call('intrawatch:worker:status'));
        $this->assertStringContainsString('Shard 1: last seen 90 s ago', Artisan::output());
        $this->assertStringNotContainsString('Shard 0', Artisan::output());

        DB::table('check_workers')->where('shard', 1)->update(['last_seen_at' => $now->subSeconds(2)]);
        $this->assertSame(0, Artisan::call('intrawatch:worker:status'));

        DB::table('check_workers')->where('shard', 1)->update(['shards' => 3]);
        $this->assertSame(1, Artisan::call('intrawatch:worker:status'));
        $this->assertStringContainsString('Shard 1: runs with 3 shards, expected 2', Artisan::output());

        DB::table('check_workers')->where('shard', 1)->delete();
        $this->assertSame(1, Artisan::call('intrawatch:worker:status'));
        $this->assertStringContainsString('Shard 1: never reported', Artisan::output());
    }

    public function test_status_command_checks_only_selected_or_local_shards(): void
    {
        config(['monitoring.worker.shards_total' => 4, 'monitoring.worker.shard_offset' => 2, 'monitoring.worker.workers' => 2]);
        $now = CarbonImmutable::now();
        $row = fn (int $shard, CarbonImmutable $seen): array => [
            'shard' => $shard, 'shards' => 4, 'hostname' => 'worker-b', 'pid' => 200 + $shard, 'started_at' => $now->subHour(),
            'last_seen_at' => $seen, 'monitors' => 10, 'checks_last_minute' => 10, 'max_drift_ms_last_minute' => 5, 'buffered' => 0,
        ];
        // Shard 0 runs on another host and is stale; shard 1 never reported.
        DB::table('check_workers')->insert([$row(0, $now->subSeconds(300)), $row(2, $now->subSeconds(5)), $row(3, $now->subSeconds(5))]);

        $this->assertSame(1, Artisan::call('intrawatch:worker:status'));
        $this->assertSame(0, Artisan::call('intrawatch:worker:status', ['--local' => true]));
        $this->assertStringContainsString('Shards 2, 3 of 4 reported', Artisan::output());
        $this->assertSame(0, Artisan::call('intrawatch:worker:status', ['--shard' => ['3', '2']]));
        $this->assertSame(1, Artisan::call('intrawatch:worker:status', ['--shard' => ['1', '2']]));
        $this->assertStringContainsString('Shard 1: never reported', Artisan::output());
        $this->assertStringNotContainsString('Shard 0', Artisan::output());

        DB::table('check_workers')->where('shard', 3)->update(['last_seen_at' => $now->subSeconds(90)]);
        $this->assertSame(1, Artisan::call('intrawatch:worker:status', ['--local' => true]));
        $this->assertStringContainsString('Shard 3: last seen 90 s ago', Artisan::output());

        $this->assertSame(2, Artisan::call('intrawatch:worker:status', ['--shard' => ['4']]));
        $this->assertSame(2, Artisan::call('intrawatch:worker:status', ['--local' => true, '--shard' => ['2']]));
        config(['monitoring.worker.shard_offset' => 3]);
        $this->assertSame(2, Artisan::call('intrawatch:worker:status', ['--local' => true]));
    }

    public function test_shutdown_skips_the_final_heartbeat_when_outcomes_could_not_be_written(): void
    {
        $this->tcpMonitors(2, ['interval_seconds' => 20, 'retry_interval_seconds' => 20]);
        $this->failResultInserts(PHP_INT_MAX);

        [$stop, $run] = $this->start($this->scheduler());
        $this->clock->advance(25);
        $lastBeat = DB::table('check_workers')->where('shard', 0)->value('last_seen_at');
        $this->assertNotNull($lastBeat);

        $stop->cancel();
        $this->clock->advance(12);
        $run->await();

        $this->assertSame($lastBeat, DB::table('check_workers')->where('shard', 0)->value('last_seen_at'),
            'a drain that leaves outcomes behind spends no more time on the database');
    }

    public function test_sigterm_flushes_and_exits_cleanly(): void
    {
        [$steady, $held] = $this->tcpMonitors(2, ['interval_seconds' => 20, 'retry_interval_seconds' => 20]);
        $this->checker->hold($held->id);

        // A buffer that never flushes on its own: everything recorded below is written by the shutdown.
        [$stop, $run] = $this->start($this->scheduler(buffer: $this->buffer(flushSeconds: 3600)));
        $this->clock->advance(25);
        $this->assertSame(0, CheckResult::count());
        $this->assertCount(1, $this->checker->callsFor($held->id));

        $stop->cancel();
        $this->clock->advance(3);
        $this->checker->release($held->id);
        $this->clock->advance(10);
        $run->await();

        $this->assertSame(count($this->checker->callsFor($steady->id)), CheckResult::where('monitor_id', $steady->id)->count());
        $this->assertGreaterThan(0, CheckResult::where('monitor_id', $steady->id)->count());
        $this->assertSame(1, CheckResult::where('monitor_id', $held->id)->count(), 'the check in flight at shutdown is recorded');
        $this->assertSame(1, DB::table('check_workers')->where('shard', 0)->count());
    }

    /**
     * A test that depends on when each monitor first falls due passes `$firstId`: the scheduler spreads first
     * checks by `crc32(id) % interval`, and only SQLite restarts ids at 1 for every test (MySQL and PostgreSQL
     * keep counting), so pinned ids keep the schedule the same on every database.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<Monitor>
     */
    private function tcpMonitors(int $count, array $attributes = [], ?int $firstId = null): array
    {
        $monitors = [];
        for ($i = 0; $i < $count; $i++) {
            $monitors[] = Monitor::factory()->for($this->ws)->tcp('example.test', 443)
                ->create($firstId === null ? $attributes : [...$attributes, 'id' => $firstId + $i]);
        }

        return $monitors;
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function resultTimes(Monitor $monitor): array
    {
        $times = [];
        foreach (CheckResult::where('monitor_id', $monitor->id)->orderBy('checked_at')->get() as $result) {
            $times[] = CarbonImmutable::instance($result->checked_at);
        }

        return $times;
    }

    private function scheduler(int $shard = 0, int $shards = 1, ?OutcomeBuffer $buffer = null, ?MaintenanceOracle $maintenance = null): Scheduler
    {
        return new Scheduler(
            shard: $shard,
            shards: $shards,
            loader: new MonitorLoader($this->clock),
            buffer: $buffer ?? $this->buffer(),
            heartbeat: new Heartbeat($this->clock, $shard, $shards),
            checkers: $this->app->make(CheckerRegistry::class),
            deadlines: new NullDeadlineEvaluator,
            clock: $this->clock,
            maintenance: $maintenance,
        );
    }

    private function buffer(int $capacity = 10_000, float $flushSeconds = 1.0): OutcomeBuffer
    {
        return new OutcomeBuffer(
            recorder: $this->app->make(ResultRecorder::class),
            clock: $this->clock,
            capacity: $capacity,
            flushSeconds: $flushSeconds,
            reconnect: function (): void {
                $this->reconnects++;
            },
        );
    }

    /**
     * Makes the next inserts into check_results fail the way a lost database does.
     */
    private function failResultInserts(int $times): FailureBudget
    {
        $failures = new FailureBudget($times);

        DB::connection()->beforeExecuting(function (string $query) use ($failures): void {
            if ($failures->remaining > 0 && str_starts_with($query, 'insert into') && str_contains($query, 'check_results')) {
                $failures->remaining--;
                throw new QueryException(DB::connection()->getName() ?? 'default', $query, [], new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'));
            }
        });

        return $failures;
    }

    /**
     * @return array{0: DeferredCancellation, 1: Future<void>}
     */
    private function start(Scheduler $scheduler): array
    {
        $stop = new DeferredCancellation;
        $run = async(fn () => $scheduler->run($stop->getCancellation()));
        $this->clock->settle();

        return [$stop, $run];
    }

    /**
     * @param  Future<void>  $run
     */
    private function stop(DeferredCancellation $stop, Future $run): void
    {
        $stop->cancel();
        $this->clock->advance(12);
        $run->await();
    }
}
