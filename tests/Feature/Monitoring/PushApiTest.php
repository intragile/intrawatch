<?php

namespace Tests\Feature\Monitoring;

use Amp\DeferredCancellation;
use Amp\Future;
use App\Enums\MonitorStatus;
use App\Enums\WorkspaceRole;
use App\Events\ChecksRecorded;
use App\Models\CheckResult;
use App\Models\Monitor;
use App\Models\User;
use App\Models\Workspace;
use App\Monitoring\Checks\CheckerRegistry;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\DatabaseDeadlineEvaluator;
use App\Monitoring\Engine\DeadlineEvaluator;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Engine\ResultRecorder;
use App\Monitoring\Worker\Heartbeat;
use App\Monitoring\Worker\MonitorLoader;
use App\Monitoring\Worker\OutcomeBuffer;
use App\Monitoring\Worker\Scheduler;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDOException;
use Tests\Support\FailureBudget;
use Tests\Support\FakeClock;
use Tests\TestCase;

use function Amp\async;

class PushApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeClock $clock;

    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FakeClock(CarbonImmutable::parse('2026-11-06 10:00:00', 'UTC'));
        $this->ws = Workspace::factory()->create();
    }

    public function test_push_records_up_with_latency_and_message(): void
    {
        Event::fake([ChecksRecorded::class]);
        $monitor = $this->push();

        $this->getJson("/api/v1/push/{$monitor->push_token}?ping=42&msg=ok")
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        $result = CheckResult::where('monitor_id', $monitor->id)->sole();
        $this->assertSame(MonitorStatus::Up->value, $result->getRawOriginal('status'));
        $this->assertSame(42, $result->latency_ms);
        $this->assertSame('ok', $result->message);

        $state = $monitor->state()->firstOrFail();
        $this->assertSame(MonitorStatus::Up, $state->status);
        $this->assertNotNull($state->last_ping_at);
        $this->assertTrue($this->clock->now()->equalTo($state->last_ping_at));
        Event::assertDispatched(ChecksRecorded::class);

        $this->postJson("/api/v1/push/{$monitor->push_token}")->assertOk()->assertExactJson(['ok' => true]);
        $this->call('HEAD', "/api/v1/push/{$monitor->push_token}")->assertOk();
        $this->assertSame(3, CheckResult::where('monitor_id', $monitor->id)->count());
    }

    public function test_push_down_is_final_long_messages_are_truncated_and_bad_pings_ignored(): void
    {
        $monitor = $this->push(['retries' => 3]);

        $this->getJson("/api/v1/push/{$monitor->push_token}?status=down&ping=-5&msg=".str_repeat('x', 250))->assertOk();

        $result = CheckResult::where('monitor_id', $monitor->id)->sole();
        $this->assertSame(MonitorStatus::Down->value, $result->getRawOriginal('status'), 'a reported down skips the retries');
        $this->assertNull($result->latency_ms);
        $this->assertSame(str_repeat('x', 200), $result->message);

        foreach (['abc', '1.5', '12abc', '99999999999999999999'] as $ping) {
            $this->getJson("/api/v1/push/{$monitor->push_token}?ping={$ping}")->assertOk();
        }
        $this->assertSame(0, CheckResult::where('monitor_id', $monitor->id)->whereNotNull('latency_ms')->count());

        $this->getJson("/api/v1/push/{$monitor->push_token}?status=DOWN")->assertOk();
        $this->assertSame(MonitorStatus::Down, CheckResult::where('monitor_id', $monitor->id)->latest('id')->firstOrFail()->status, 'status is case-insensitive');
    }

    public function test_push_unknown_token_inactive_and_wrong_route_are_uniform_404(): void
    {
        $inactive = $this->push(['active' => false]);
        $http = Monitor::factory()->for($this->ws)->http()->create(['push_token' => str_repeat('h', 48)]);
        $push = $this->push();
        $this->assertIsString($push->push_token);

        $urls = [
            '/api/v1/push/'.str_repeat('u', 48),
            "/api/v1/push/{$inactive->push_token}",
            "/api/v1/push/{$http->push_token}",
            "/api/v1/push/{$push->push_token}/start",
            "/api/v1/push/{$push->push_token}/0",
            "/api/v1/push/{$push->push_token}/3",
            '/api/v1/push/'.strtoupper($push->push_token),
            "/api/v1/push/{$inactive->push_token}/fail",
        ];

        $bodies = [];
        $headers = [];
        foreach (['GET', 'POST', 'HEAD'] as $method) {
            foreach ($urls as $url) {
                $response = $this->json($method, $url)->assertNotFound();
                $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
                $names = array_values(array_diff(array_keys($response->headers->all()), ['date']));
                sort($names);
                $headers[] = implode(',', $names);
                if ($method !== 'HEAD') {
                    $bodies[] = $response->getContent();
                }
            }
        }
        $this->assertCount(1, array_unique($bodies), 'every miss has the same body');
        $this->assertCount(1, array_unique($headers), 'every miss has the same headers');
        $this->assertSame(0, CheckResult::count());
    }

    public function test_missed_push_heartbeat_goes_pending_then_down(): void
    {
        $monitor = $this->push(['retries' => 1, 'interval_seconds' => 60], grace: 30);
        $start = $this->clock->now();

        [$outcome, $next] = $this->evaluate($monitor);
        $this->assertNull($outcome);
        $this->assertTrue($start->addSeconds(91)->equalTo($next), 'evaluated again one second after the deadline');

        $this->clock->advance(90);
        [$outcome] = $this->evaluate($monitor);
        $this->assertNull($outcome, 'the deadline itself is not missed yet');

        $this->clock->advance(1);
        [$outcome, $next] = $this->evaluate($monitor);
        $this->assertNotNull($outcome);
        $this->assertSame('No heartbeat for 91 s', $outcome->message);
        $this->assertTrue($start->addSeconds(151)->equalTo($next));
        $this->record($monitor, $outcome);
        $this->assertSame(MonitorStatus::Pending, $monitor->state()->firstOrFail()->status);

        $this->clock->advance(60);
        [$outcome] = $this->evaluate($monitor);
        $this->assertNotNull($outcome);
        $this->record($monitor, $outcome);
        $this->assertSame(MonitorStatus::Down, $monitor->state()->firstOrFail()->status);

        $this->clock->advance(60);
        [$outcome, $next] = $this->evaluate($monitor);
        $this->assertNull($outcome, 'one missed deadline is reported down once');
        $this->assertTrue($this->clock->now()->addSeconds(60)->equalTo($next));

        $this->getJson("/api/v1/push/{$monitor->push_token}")->assertOk();
        $this->assertSame(MonitorStatus::Up, $monitor->state()->firstOrFail()->status);
        [$outcome, $next] = $this->evaluate($monitor);
        $this->assertNull($outcome);
        $this->assertTrue($this->clock->now()->addSeconds(91)->equalTo($next));
    }

    public function test_a_ping_after_the_evaluation_cancels_the_missed_heartbeat(): void
    {
        $monitor = $this->push(['retries' => 0], grace: 30);

        $this->clock->advance(91);
        [$outcome] = $this->evaluate($monitor);
        $this->assertNotNull($outcome);

        $this->clock->advance(1);
        $this->getJson("/api/v1/push/{$monitor->push_token}")->assertOk();
        $this->record($monitor, $outcome);

        $this->assertSame(MonitorStatus::Up, $monitor->state()->firstOrFail()->status);
        $this->assertSame([MonitorStatus::Up], CheckResult::where('monitor_id', $monitor->id)->get()->pluck('status')->all());
    }

    public function test_created_resumed_and_edited_monitors_restart_the_deadline(): void
    {
        $editor = User::factory()->memberOf($this->ws, WorkspaceRole::Editor)->create();
        $url = "/api/v1/workspaces/{$this->ws->slug}/monitors";

        $this->clock->advance(3600);
        $created = $this->actingAs($editor)->postJson($url, [
            'name' => 'nightly backup', 'type' => 'push', 'interval_seconds' => 60, 'retries' => 0,
            'config' => ['grace_seconds' => 30],
        ])->assertCreated()->json('data.id');
        $this->assertIsInt($created);
        $monitor = Monitor::query()->findOrFail($created);
        [$outcome, $next] = $this->evaluate($monitor);
        $this->assertNull($outcome);
        $this->assertTrue($this->clock->now()->addSeconds(91)->equalTo($next), 'counted from creation');

        $this->actingAs($editor)->postJson("{$url}/{$monitor->id}/pause")->assertOk();
        $this->clock->advance(7200);
        $this->actingAs($editor)->postJson("{$url}/{$monitor->id}/resume")->assertOk();
        [$outcome, $next] = $this->evaluate($monitor);
        $this->assertNull($outcome, 'a resume never yields an instant down');
        $this->assertTrue($this->clock->now()->addSeconds(91)->equalTo($next));

        $this->clock->advance(80);
        $this->actingAs($editor)->patchJson("{$url}/{$monitor->id}", ['config' => ['grace_seconds' => 40]])->assertOk();
        $this->clock->advance(20);
        [$outcome, $next] = $this->evaluate($monitor);
        $this->assertNull($outcome, 'an edit restarts the deadline');
        $this->assertTrue($this->clock->now()->addSeconds(81)->equalTo($next));

        $this->actingAs($editor)->patchJson("{$url}/{$monitor->id}", ['name' => 'renamed'])->assertOk();
        $this->clock->advance(81);
        [$outcome] = $this->evaluate($monitor);
        $this->assertNotNull($outcome, 'a rename does not move the deadline');
    }

    public function test_push_is_rate_limited(): void
    {
        $monitor = $this->push();
        for ($i = 0; $i < 120; $i++) {
            $this->getJson("/api/v1/push/{$monitor->push_token}")->assertOk();
        }

        $response = $this->getJson("/api/v1/push/{$monitor->push_token}")->assertStatus(429);
        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $this->assertSame(429, $response->json('status'));

        $other = $this->push();
        $this->getJson("/api/v1/push/{$other->push_token}")->assertOk();
    }

    public function test_unknown_tokens_are_rate_limited_per_ip(): void
    {
        $monitor = $this->push();
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/push/'.str_pad((string) $i, 48, 'g'))->assertNotFound();
        }

        $response = $this->getJson('/api/v1/push/'.str_repeat('z', 48))->assertStatus(429);
        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $this->getJson("/api/v1/push/{$monitor->push_token}")->assertOk();
        $this->getJson("/api/v1/push/{$monitor->push_token}")->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.7'])->getJson('/api/v1/push/'.str_repeat('z', 48))->assertNotFound();
    }

    public function test_repeated_evaluation_of_one_miss_never_consumes_a_retry(): void
    {
        $monitor = $this->push(['retries' => 1, 'interval_seconds' => 60, 'retry_interval_seconds' => 60], grace: 30);

        $this->clock->advance(91);
        // As if the worker restarted, the monitor was re-added or two workers owned the shard: the same miss
        // is evaluated three times before any of it is recorded.
        $outcomes = [];
        for ($i = 0; $i < 3; $i++) {
            [$outcome] = $this->evaluate($monitor);
            $this->assertNotNull($outcome);
            $outcomes[] = $outcome;
        }
        foreach ($outcomes as $outcome) {
            $this->record($monitor, $outcome);
        }
        [$outcome] = $this->evaluate($monitor);
        $this->assertNull($outcome, 'a recorded miss is not evaluated again');
        $this->assertSame(MonitorStatus::Pending, $monitor->state()->firstOrFail()->status);
        $this->assertSame(1, CheckResult::where('monitor_id', $monitor->id)->count());

        $this->clock->advance(59);
        [$outcome, $next] = $this->evaluate($monitor);
        $this->assertNull($outcome, 'the next miss is one retry interval later');
        $this->assertTrue($this->clock->now()->addSeconds(1)->equalTo($next));
        $this->assertSame(MonitorStatus::Pending, $monitor->state()->firstOrFail()->status);

        $this->clock->advance(1);
        [$outcome] = $this->evaluate($monitor);
        $this->assertNotNull($outcome);
        $this->record($monitor, $outcome);
        $this->assertSame(MonitorStatus::Down, $monitor->state()->firstOrFail()->status);
    }

    public function test_database_errors_never_leak_the_token(): void
    {
        $monitor = $this->push();
        $token = (string) $monitor->push_token;
        $this->assertTrue((bool) DB::connection()->getConfig('mask_bindings_in_exception_messages'));

        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e->message.' '.json_encode($e->context);
        });
        $failing = false;
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$failing): void {
            if (! $failing && str_contains($query, 'push_token')) {
                $failing = true;
                // A real failing statement, so the exception message is built the way the connection builds it.
                DB::connection()->select('select * from missing_table where push_token = ?', $bindings);
            }
        });

        $response = $this->getJson("/api/v1/push/{$token}")->assertStatus(503);

        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $this->assertStringNotContainsString($token, (string) $response->getContent());
        $this->assertNotSame([], $logged);
        foreach ($logged as $line) {
            $this->assertStringNotContainsString($token, $line);
        }
    }

    public function test_a_heartbeat_missed_before_the_worker_started_is_reanchored(): void
    {
        $monitor = $this->push(['retries' => 0], grace: 30);
        $this->clock->advance(3600);
        $started = $this->clock->now();

        [$stop, $run] = $this->startScheduler();
        $this->clock->advance(90);
        $this->assertSame(0, CheckResult::where('monitor_id', $monitor->id)->count(), 'a full interval and grace from the worker start');

        $this->clock->advance(5);
        $this->stopScheduler($stop, $run);

        $result = CheckResult::where('monitor_id', $monitor->id)->sole();
        $this->assertSame(MonitorStatus::Down, $result->status);
        $this->assertTrue($started->addSeconds(91)->lte($result->checked_at));
    }

    public function test_a_heartbeat_missed_during_an_observed_outage_is_reanchored(): void
    {
        $monitor = $this->push(['retries' => 0], grace: 30);
        $outage = new FailureBudget(PHP_INT_MAX);
        $this->clock->advance(10);
        DB::connection()->beforeExecuting(function (string $query) use ($outage): void {
            if ($outage->remaining > 0 && str_contains($query, 'deadline_anchor_at') && ! str_contains($query, 'consecutive_failures')) {
                throw new QueryException('sqlite', $query, [], new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'));
            }
        });

        [$stop, $run] = $this->startScheduler();
        $this->clock->advance(300);
        $this->assertSame(0, CheckResult::where('monitor_id', $monitor->id)->count());

        $outage->remaining = 0;
        $this->clock->advance(31);
        $back = $this->clock->now();
        $this->clock->advance(60);
        $this->assertSame(0, CheckResult::where('monitor_id', $monitor->id)->count(), 'a full interval and grace from the recovery');

        $this->clock->advance(45);
        $this->stopScheduler($stop, $run);
        $result = CheckResult::where('monitor_id', $monitor->id)->sole();
        $this->assertTrue($back->addSeconds(89)->lte($result->checked_at));
    }

    public function test_the_buffer_reports_when_the_database_came_back(): void
    {
        $monitor = $this->push();
        $buffer = new OutcomeBuffer($this->app->make(ResultRecorder::class), $this->clock, reconnect: fn () => null);
        $this->assertRecoveredAt($buffer, null);

        $fail = new FailureBudget(PHP_INT_MAX);
        DB::connection()->beforeExecuting(function (string $query) use ($fail): void {
            if ($fail->remaining > 0 && str_starts_with($query, 'insert into') && str_contains($query, 'check_results')) {
                throw new QueryException('sqlite', $query, [], new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'));
            }
        });
        $buffer->push(MonitorSpec::fromModel($monitor), CheckOutcome::up(), $this->clock->now(), null, fn () => null);
        $this->assertFalse($buffer->flush());
        $this->assertRecoveredAt($buffer, null);

        $fail->remaining = 0;
        $this->clock->advance(30);
        $this->assertTrue($buffer->flush());
        $this->assertRecoveredAt($buffer, $this->clock->now());
    }

    private function assertRecoveredAt(OutcomeBuffer $buffer, ?CarbonImmutable $expected): void
    {
        $recovered = $buffer->recoveredAt();
        if ($expected === null) {
            $this->assertNull($recovered);

            return;
        }
        $this->assertNotNull($recovered);
        $this->assertTrue($expected->equalTo($recovered));
    }

    public function test_deadlines_are_read_in_one_batched_query_per_tick(): void
    {
        $monitors = [];
        for ($i = 0; $i < 5; $i++) {
            $monitors[] = $this->push(['retries' => 0], grace: 30);
        }

        $reads = 0;
        DB::listen(function ($query) use (&$reads): void {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'monitor_states')
                && str_contains($query->sql, 'deadline_anchor_at') && ! str_contains($query->sql, 'consecutive_failures')) {
                $reads++;
            }
        });

        $scheduler = new Scheduler(
            shard: 0,
            shards: 1,
            loader: new MonitorLoader($this->clock),
            buffer: new OutcomeBuffer($this->app->make(ResultRecorder::class), $this->clock),
            heartbeat: new Heartbeat($this->clock, 0, 1),
            checkers: $this->app->make(CheckerRegistry::class),
            deadlines: $this->app->make(DeadlineEvaluator::class),
            clock: $this->clock,
        );
        $stop = new DeferredCancellation;
        $run = async(fn () => $scheduler->run($stop->getCancellation()));
        $this->clock->settle();
        $this->assertSame(1, $reads, 'all deadline monitors are evaluated together on start');

        $this->clock->advance(95);
        $stop->cancel();
        $this->clock->advance(12);
        $run->await();

        $this->assertSame(2, $reads, 'the shared deadline is evaluated in one read');
        foreach ($monitors as $monitor) {
            $this->assertSame(MonitorStatus::Down, $monitor->state()->firstOrFail()->status);
        }
    }

    /**
     * @return array{0: DeferredCancellation, 1: Future<void>}
     */
    private function startScheduler(): array
    {
        $scheduler = new Scheduler(
            shard: 0,
            shards: 1,
            loader: new MonitorLoader($this->clock),
            buffer: new OutcomeBuffer($this->app->make(ResultRecorder::class), $this->clock, reconnect: fn () => null),
            heartbeat: new Heartbeat($this->clock, 0, 1),
            checkers: $this->app->make(CheckerRegistry::class),
            deadlines: $this->app->make(DeadlineEvaluator::class),
            clock: $this->clock,
        );
        $stop = new DeferredCancellation;
        $run = async(fn () => $scheduler->run($stop->getCancellation()));
        $this->clock->settle();

        return [$stop, $run];
    }

    /**
     * @param  Future<void>  $run
     */
    private function stopScheduler(DeferredCancellation $stop, Future $run): void
    {
        $stop->cancel();
        $this->clock->advance(12);
        $run->await();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function push(array $attributes = [], int $grace = 30): Monitor
    {
        return Monitor::factory()->for($this->ws)->push($grace)->create($attributes + ['interval_seconds' => 60]);
    }

    /**
     * @return array{0: ?CheckOutcome, 1: CarbonImmutable}
     */
    private function evaluate(Monitor $monitor): array
    {
        $this->assertInstanceOf(DatabaseDeadlineEvaluator::class, $this->app->make(DeadlineEvaluator::class));

        return $this->app->make(DeadlineEvaluator::class)->evaluate(MonitorSpec::fromModel($monitor->fresh() ?? $monitor), $this->clock->now());
    }

    private function record(Monitor $monitor, CheckOutcome $outcome): void
    {
        $this->app->make(ResultRecorder::class)->record([[MonitorSpec::fromModel($monitor->fresh() ?? $monitor), $outcome, $this->clock->now(), null]]);
    }
}
