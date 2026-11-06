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
use App\Monitoring\Engine\Deadline;
use App\Monitoring\Engine\DeadlineEvaluator;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Engine\ResultRecorder;
use App\Monitoring\Worker\Heartbeat;
use App\Monitoring\Worker\MonitorLoader;
use App\Monitoring\Worker\OutageTracker;
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
        $t0 = $this->clock->now();
        $monitor = $this->push(['retries' => 0], grace: 30);
        $outage = new FailureBudget(PHP_INT_MAX);
        $this->clock->advance(10);
        DB::connection()->beforeExecuting(function (string $query) use ($outage): void {
            if ($outage->remaining > 0 && $this->isDeadlineRead($query)) {
                throw new QueryException('sqlite', $query, [], new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'));
            }
        });

        // Reads fail from +10 s to +210 s and succeed again at +220 s: the deadline at +90 s fell inside.
        [$stop, $run] = $this->startScheduler();
        $this->clock->advance(200);
        $outage->remaining = 0;
        $this->clock->advance(110);
        $this->stopScheduler($stop, $run);

        // The deadline was re-anchored at the recovery and is missed a full interval and grace after it.
        $anchor = DB::table('monitor_states')->where('monitor_id', $monitor->id)->value('deadline_anchor_at');
        $this->assertIsString($anchor);
        $recovered = CarbonImmutable::parse($anchor)->getTimestamp();
        $this->assertGreaterThanOrEqual($t0->getTimestamp() + 210, $recovered);
        $this->assertLessThanOrEqual($t0->getTimestamp() + 221, $recovered);
        $result = CheckResult::where('monitor_id', $monitor->id)->sole();
        $missedAfter = CarbonImmutable::instance($result->checked_at)->getTimestamp() - $recovered;
        $this->assertGreaterThanOrEqual(91, $missedAfter);
        $this->assertLessThanOrEqual(92, $missedAfter);
    }

    public function test_short_database_failures_never_keep_a_dead_monitor_from_going_down(): void
    {
        $t0 = $this->clock->now();
        $monitor = $this->push(['retries' => 1, 'interval_seconds' => 60, 'retry_interval_seconds' => 60], grace: 30);

        // Every 45 s, reads and writes fail for 10 s: transient errors, never an outage.
        DB::connection()->beforeExecuting(function (string $query) use ($t0): void {
            $elapsed = $this->clock->now()->getTimestamp() - $t0->getTimestamp();
            $failing = $elapsed % 45 < 10;
            $write = str_starts_with($query, 'insert into') && str_contains($query, 'check_results');
            if ($failing && ($write || $this->isDeadlineRead($query))) {
                throw new QueryException('sqlite', $query, [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found'));
            }
        });

        [$stop, $run] = $this->startScheduler();
        $this->clock->advance(200);
        $this->stopScheduler($stop, $run);

        // Deadline at +90 s: miss 0 at +91 s (pending), miss 1 at +151 s (down), give or take a failed attempt.
        $this->assertSame(MonitorStatus::Down, $monitor->state()->firstOrFail()->status);
    }

    public function test_misses_follow_the_retry_interval(): void
    {
        $monitor = $this->push(['retries' => 1, 'interval_seconds' => 60, 'retry_interval_seconds' => 20], grace: 30);

        $this->clock->advance(91);
        [$outcome, $next] = $this->evaluate($monitor);
        $this->assertNotNull($outcome);
        $this->assertTrue($this->clock->now()->addSeconds(20)->equalTo($next), 'the second miss is due at the deadline + 21 s');
        $this->record($monitor, $outcome);

        $this->clock->advance(19);
        [$outcome] = $this->evaluate($monitor);
        $this->assertNull($outcome);

        $this->clock->advance(1);
        [$outcome] = $this->evaluate($monitor);
        $this->assertNotNull($outcome);
        $this->record($monitor, $outcome);
        $this->assertSame(MonitorStatus::Down, $monitor->state()->firstOrFail()->status);
    }

    public function test_a_recorded_miss_survives_a_worker_restart(): void
    {
        $monitor = $this->push(['retries' => 1, 'interval_seconds' => 60, 'retry_interval_seconds' => 60], grace: 30);
        $this->clock->advance(91);
        [$outcome] = $this->evaluate($monitor);
        $this->assertNotNull($outcome);
        $this->record($monitor, $outcome);
        $this->assertSame(MonitorStatus::Pending, $monitor->state()->firstOrFail()->status);

        // The worker starts after the miss was recorded: the deadline is not moved, the retries continue.
        $this->clock->advance(9);
        [$stop, $run] = $this->startScheduler();
        $this->clock->advance(65);
        $this->stopScheduler($stop, $run);

        $this->assertSame(MonitorStatus::Down, $monitor->state()->firstOrFail()->status);
        $this->assertSame(2, CheckResult::where('monitor_id', $monitor->id)->count());
    }

    public function test_a_recorded_miss_survives_an_outage(): void
    {
        $monitor = $this->push(['retries' => 1, 'interval_seconds' => 60, 'retry_interval_seconds' => 60], grace: 30);
        $t0 = $this->clock->now();
        [$stop, $run] = $this->startScheduler();

        // Miss 0 is recorded at +91 s; everything fails from +95 s to +140 s, before miss 1 is due at +150 s.
        $this->failEverythingBetween($t0, 95, 140);
        $this->clock->advance(200);
        $this->stopScheduler($stop, $run);

        $this->assertSame(MonitorStatus::Down, $monitor->state()->firstOrFail()->status, 'the outage does not reset the retries');
        $this->assertSame(2, CheckResult::where('monitor_id', $monitor->id)->count());
    }

    public function test_a_push_deadline_whose_own_read_reveals_the_outage_is_reanchored(): void
    {
        $monitor = $this->push(['retries' => 0], grace: 30);
        $t0 = $this->clock->now();
        [$stop, $run] = $this->startScheduler();

        // The deadline is at +90 s and evaluated at +91 s: that read is the first database call that fails.
        $this->failEverythingBetween($t0, 91, 200);
        $this->clock->advance(280);
        $this->assertSame(0, CheckResult::where('monitor_id', $monitor->id)->count(), 'the heartbeat may have been lost in the outage');
        $anchor = DB::table('monitor_states')->where('monitor_id', $monitor->id)->value('deadline_anchor_at');
        $this->assertIsString($anchor);
        $this->assertGreaterThanOrEqual($t0->getTimestamp() + 200, CarbonImmutable::parse($anchor)->getTimestamp(), 'anchored at the recovery');

        // Still silent a full period after the recovery: now it is down.
        $this->clock->advance(40);
        $this->stopScheduler($stop, $run);
        $this->assertSame(MonitorStatus::Down, $monitor->state()->firstOrFail()->status);
    }

    public function test_a_cron_run_whose_own_read_reveals_the_outage_is_reanchored(): void
    {
        $monitor = Monitor::factory()->for($this->ws)->cron('*/5 * * * *', 'UTC', 30)->create(['interval_seconds' => 60]);
        $t0 = $this->clock->now();
        [$stop, $run] = $this->startScheduler();

        // The 10:05 run is due by 10:05:30 (+330 s) and evaluated at +331 s: that read fails first.
        $this->failEverythingBetween($t0, 331, 500);
        $this->clock->advance(620);
        $this->stopScheduler($stop, $run);

        $this->assertSame([], CheckResult::where('monitor_id', $monitor->id)->pluck('message')->all());
    }

    public function test_a_second_outage_never_brings_back_a_run_lost_in_the_first(): void
    {
        $monitor = Monitor::factory()->for($this->ws)->cron('*/5 * * * *', 'UTC', 30)->create(['interval_seconds' => 60]);
        $t0 = $this->clock->now();
        [$stop, $run] = $this->startScheduler();

        // Outage 1 covers the 10:05 run (due by +330 s); outage 2 comes later and covers nothing.
        $this->failEverythingBetween($t0, 280, 340, 400, 440);
        $this->clock->advance(620);
        $this->stopScheduler($stop, $run);

        $this->assertSame([], CheckResult::where('monitor_id', $monitor->id)->pluck('message')->all());
    }

    public function test_a_read_success_never_ends_a_write_outage(): void
    {
        $outages = new OutageTracker;
        $start = $this->clock->now();
        $ended = [];
        $outages->onOutageEnded(function (CarbonImmutable $from, CarbonImmutable $to) use (&$ended): void {
            $ended[] = [$from->getTimestamp(), $to->getTimestamp()];
        });

        $outages->writeFailed($start);
        $outages->readSucceeded($start->addSeconds(40));
        $this->assertNotNull($outages->failingSince(), 'reads work again, writes still fail');
        $outages->writeFailed($start->addSeconds(45));
        $outages->readSucceeded($start->addSeconds(50));
        $this->assertNotNull($outages->failingSince(), 'no write has succeeded since the last write failure');
        $this->assertSame([], $ended);

        $outages->writeSucceeded($start->addSeconds(60));
        $this->assertNull($outages->failingSince());
        $this->assertSame([[$start->getTimestamp(), $start->getTimestamp() + 60]], $ended);
    }

    public function test_only_a_failure_of_at_least_30_seconds_is_an_outage(): void
    {
        $outages = new OutageTracker;
        $start = $this->clock->now();
        $ended = [];
        $outages->onOutageEnded(function (CarbonImmutable $from, CarbonImmutable $to) use (&$ended): void {
            $ended[] = [$from->getTimestamp(), $to->getTimestamp()];
        });

        $outages->readFailed($start->addSeconds(10), $start);
        $outages->readSucceeded($start->addSeconds(20));
        $outages->writeSucceeded($start->addSeconds(29));
        $this->assertSame([], $ended, 'a burst shorter than 30 s');
        $this->assertNull($outages->failingSince());

        // The failure starts at the earliest due moment of what failed, when that is earlier.
        $outages->writeFailed($start->addSeconds(100), $start->addSeconds(95));
        $this->assertSame($start->getTimestamp() + 95, $outages->failingSince()?->getTimestamp());
        $outages->writeSucceeded($start->addSeconds(120));
        $outages->readSucceeded($start->addSeconds(125));
        $this->assertSame([[$start->getTimestamp() + 95, $start->getTimestamp() + 125]], $ended);
    }

    public function test_a_failed_write_of_a_missed_deadline_starts_the_failure_at_that_deadline(): void
    {
        $monitor = $this->push(['retries' => 0], grace: 30);
        $t0 = $this->clock->now();
        $buffer = new OutcomeBuffer($this->app->make(ResultRecorder::class), $this->clock, reconnect: fn () => null);
        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_starts_with($query, 'insert into') && str_contains($query, 'check_results')) {
                throw new QueryException('sqlite', $query, [], new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'));
            }
        });

        $this->clock->advance(95);
        $outcome = CheckOutcome::down('No heartbeat')->forDeadline(new Deadline($t0->addSeconds(90), $t0, null, 0));
        $buffer->push(MonitorSpec::fromModel($monitor), $outcome, $this->clock->now(), null, fn () => null);
        $this->assertFalse($buffer->flush());

        $this->assertSame($t0->getTimestamp() + 90, $buffer->outages()->failingSince()?->getTimestamp());
    }

    public function test_errors_other_than_database_errors_are_no_outage(): void
    {
        $monitor = $this->push(['retries' => 0], grace: 30);
        $broken = new FailureBudget(1);
        $evaluator = new class($this->app->make(DatabaseDeadlineEvaluator::class), $broken) implements DeadlineEvaluator
        {
            public function __construct(private readonly DatabaseDeadlineEvaluator $inner, private readonly FailureBudget $broken) {}

            public function evaluate(MonitorSpec $spec, CarbonImmutable $now): array
            {
                return $this->evaluateMany([$spec], $now)[$spec->id];
            }

            public function evaluateMany(array $specs, CarbonImmutable $now): array
            {
                if ($this->broken->remaining > 0) {
                    throw new \RuntimeException('Evaluator bug');
                }

                return $this->inner->evaluateMany($specs, $now);
            }

            public function reanchor(array $specs, ?CarbonImmutable $from, CarbonImmutable $to): int
            {
                return $this->inner->reanchor($specs, $from, $to);
            }
        };
        $buffer = new OutcomeBuffer($this->app->make(ResultRecorder::class), $this->clock, reconnect: fn () => null);

        [$stop, $run] = $this->startScheduler($evaluator, $buffer);
        $this->clock->advance(60);
        $this->assertNull($buffer->outages()->failingSince(), 'an evaluator bug is no database failure');
        $broken->remaining = 0;
        $this->clock->advance(40);
        $this->stopScheduler($stop, $run);

        $this->assertSame(MonitorStatus::Down, $monitor->state()->firstOrFail()->status, 'the deadline at +90 s was not moved');
    }

    public function test_the_worker_start_reanchors_missed_deadlines_at_whole_seconds(): void
    {
        $monitor = $this->push(['retries' => 1, 'interval_seconds' => 60, 'retry_interval_seconds' => 60], grace: 30);
        $t0 = $this->clock->now();
        $this->clock->advance(3600.4);

        [$stop, $run] = $this->startScheduler();
        $this->clock->advance(5);
        $anchor = DB::table('monitor_states')->where('monitor_id', $monitor->id)->value('deadline_anchor_at');
        $this->assertIsString($anchor);
        $this->assertSame($t0->getTimestamp() + 3600, CarbonImmutable::parse($anchor)->getTimestamp());
        $this->assertSame(0, CarbonImmutable::parse($anchor)->microsecond);

        // Missed again a full period later: one miss, counted once however often it is evaluated.
        $this->clock->advance(95);
        $this->stopScheduler($stop, $run);
        $this->assertSame(1, CheckResult::where('monitor_id', $monitor->id)->count());
        $this->assertSame(MonitorStatus::Pending, $monitor->state()->firstOrFail()->status);
    }

    public function test_a_failing_heartbeat_never_holds_back_a_missed_deadline(): void
    {
        $monitor = $this->push(['retries' => 0], grace: 30);
        $t0 = $this->clock->now();
        DB::connection()->beforeExecuting(function (string $query) use ($t0): void {
            if ($this->clock->now()->getTimestamp() - $t0->getTimestamp() >= 5 && str_contains($query, 'check_workers')) {
                throw new QueryException('sqlite', $query, [], new PDOException('SQLSTATE[42000]: Syntax error or access violation: 1142 UPDATE command denied'));
            }
        });

        [$stop, $run] = $this->startScheduler();
        $this->clock->advance(100);
        $this->stopScheduler($stop, $run);

        $result = CheckResult::where('monitor_id', $monitor->id)->sole();
        $this->assertSame(MonitorStatus::Down, $result->status);
        $this->assertLessThanOrEqual($t0->getTimestamp() + 92, CarbonImmutable::instance($result->checked_at)->getTimestamp());
    }

    public function test_a_failing_monitor_poll_never_holds_back_a_missed_deadline(): void
    {
        $monitor = $this->push(['retries' => 0], grace: 30);
        $t0 = $this->clock->now();
        DB::connection()->beforeExecuting(function (string $query) use ($t0): void {
            // The shard filter of the monitor poll; the shard was loaded before.
            if ($this->clock->now()->getTimestamp() - $t0->getTimestamp() >= 5 && str_contains($query, '% ?')) {
                throw new QueryException('sqlite', $query, [], new PDOException('SQLSTATE[42S22]: Column not found'));
            }
        });

        [$stop, $run] = $this->startScheduler();
        $this->clock->advance(100);
        $this->stopScheduler($stop, $run);

        $result = CheckResult::where('monitor_id', $monitor->id)->sole();
        $this->assertSame(MonitorStatus::Down, $result->status);
        $this->assertLessThanOrEqual($t0->getTimestamp() + 92, CarbonImmutable::instance($result->checked_at)->getTimestamp());
    }

    public function test_missed_deadlines_are_held_back_for_at_most_five_minutes_of_a_failure(): void
    {
        $monitor = $this->push(['retries' => 0], grace: 30);
        $t0 = $this->clock->now();
        // The read of the deadline at +90 s fails at +91 s, so the failure starts at +90 s. The heartbeat fails
        // throughout and nothing else is written, so the failure never ends on its own.
        DB::connection()->beforeExecuting(function (string $query) use ($t0): void {
            $elapsed = $this->clock->now()->getTimestamp() - $t0->getTimestamp();
            if (($elapsed >= 91 && $elapsed < 100 && $this->isDeadlineRead($query)) || str_contains($query, 'check_workers')) {
                throw new QueryException('sqlite', $query, [], new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'));
            }
        });

        [$stop, $run] = $this->startScheduler();
        $this->clock->advance(385);
        $this->assertSame(0, CheckResult::where('monitor_id', $monitor->id)->count(), 'held back inside the first five minutes');

        $this->clock->advance(25);
        $this->stopScheduler($stop, $run);

        $result = CheckResult::where('monitor_id', $monitor->id)->sole();
        $this->assertSame(MonitorStatus::Down, $result->status);
        $at = CarbonImmutable::instance($result->checked_at)->getTimestamp();
        $this->assertGreaterThanOrEqual($t0->getTimestamp() + 390, $at);
        $this->assertLessThanOrEqual($t0->getTimestamp() + 401, $at, 'evaluated again within the retry delay of the cap');
    }

    public function test_the_reanchored_window_never_extends_past_the_hold_cap(): void
    {
        $outages = new OutageTracker;
        $start = $this->clock->now();
        $ended = [];
        $outages->onOutageEnded(function (CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $end) use (&$ended): void {
            $ended[] = [$from->getTimestamp(), $to->getTimestamp(), $end->getTimestamp()];
        });

        $outages->readFailed($start);
        $this->assertSame($start->getTimestamp(), $outages->holdingSince($start->addSeconds(299))?->getTimestamp());
        $this->assertNull($outages->holdingSince($start->addSeconds(300)), 'nothing is held back after five minutes');
        $this->assertNotNull($outages->failingSince());

        $outages->writeSucceeded($start->addSeconds(400));
        $outages->readSucceeded($start->addSeconds(410));
        $this->assertSame([[$start->getTimestamp(), $start->getTimestamp() + 300, $start->getTimestamp() + 410]], $ended);
    }

    public function test_chained_short_failures_with_successes_in_between_are_separate(): void
    {
        $outages = new OutageTracker;
        $start = $this->clock->now();
        $ended = [];
        $outages->onOutageEnded(function (CarbonImmutable $from, CarbonImmutable $to) use (&$ended): void {
            $ended[] = [$from->getTimestamp(), $to->getTimestamp()];
        });

        // Deadlocks every 8 s, alternating between reads and writes, with both working in between.
        for ($t = 0; $t < 600; $t += 16) {
            $outages->readFailed($start->addSeconds($t));
            $outages->writeSucceeded($start->addSeconds($t + 1));
            $outages->readSucceeded($start->addSeconds($t + 7));
            $this->assertNull($outages->failingSince(), "the read failure at +{$t} s has ended");
            $outages->writeFailed($start->addSeconds($t + 8));
            $outages->readSucceeded($start->addSeconds($t + 9));
            $outages->writeSucceeded($start->addSeconds($t + 10));
            $this->assertNull($outages->failingSince(), 'the write failure at +'.($t + 8).' s has ended');
        }
        $this->assertSame([], $ended);

        // A failure of one kind is ended only by a success of the same kind.
        $outages->writeFailed($start->addSeconds(700));
        $outages->readSucceeded($start->addSeconds(701));
        $outages->readFailed($start->addSeconds(705));
        $outages->writeSucceeded($start->addSeconds(706));
        $this->assertSame($start->getTimestamp() + 700, $outages->failingSince()?->getTimestamp(), 'the read failed after its last success');
        $outages->readSucceeded($start->addSeconds(740));
        $this->assertSame([[$start->getTimestamp() + 700, $start->getTimestamp() + 740]], $ended);
    }

    public function test_chained_deadlocks_never_reanchor_a_dead_monitor(): void
    {
        $monitor = $this->push(['retries' => 0], grace: 30);
        $t0 = $this->clock->now();
        $anchorBefore = DB::table('monitor_states')->where('monitor_id', $monitor->id)->value('deadline_anchor_at');

        // For one second in every five, every query deadlocks. The heartbeat and the monitor poll happen to
        // fall into those seconds; deadline reads and outcome writes work in between.
        DB::connection()->beforeExecuting(function (string $query) use ($t0): void {
            $elapsed = $this->clock->now()->getTimestamp() - $t0->getTimestamp();
            if ($elapsed >= 5 && $elapsed % 5 === 0) {
                throw new QueryException('sqlite', $query, [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found'));
            }
        });

        [$stop, $run] = $this->startScheduler();
        $this->clock->advance(600);
        $this->stopScheduler($stop, $run);

        $this->assertSame($anchorBefore, DB::table('monitor_states')->where('monitor_id', $monitor->id)->value('deadline_anchor_at'), 'never re-anchored');
        $result = CheckResult::where('monitor_id', $monitor->id)->sole();
        $this->assertSame(MonitorStatus::Down, $result->status);
        $this->assertLessThanOrEqual($t0->getTimestamp() + 92, CarbonImmutable::instance($result->checked_at)->getTimestamp());
    }

    public function test_a_write_only_outage_on_a_push_only_shard_never_reports_the_lost_heartbeat(): void
    {
        $monitor = $this->push(['retries' => 0], grace: 30);
        $t0 = $this->clock->now();
        // Writes fail from +20 s to +200 s (a read-only failover): pings are lost, deadline reads still work.
        DB::connection()->beforeExecuting(function (string $query) use ($t0): void {
            $elapsed = $this->clock->now()->getTimestamp() - $t0->getTimestamp();
            $lower = strtolower(ltrim($query));
            if ($elapsed >= 20 && $elapsed < 200 && (str_starts_with($lower, 'insert') || str_starts_with($lower, 'update'))) {
                throw new QueryException('sqlite', $query, [], new PDOException('SQLSTATE[HY000]: General error: 1290 The MySQL server is running with the --super-read-only option'));
            }
        });

        [$stop, $run] = $this->startScheduler();
        $this->clock->advance(280);
        $this->assertSame(0, CheckResult::where('monitor_id', $monitor->id)->count(), 'the miss of the deadline at +90 s is not recorded after the writes recover');
        $anchor = DB::table('monitor_states')->where('monitor_id', $monitor->id)->value('deadline_anchor_at');
        $this->assertIsString($anchor);
        $this->assertGreaterThanOrEqual($t0->getTimestamp() + 200, CarbonImmutable::parse($anchor)->getTimestamp(), 'anchored at the recovery');

        $this->clock->advance(60);
        $this->stopScheduler($stop, $run);
        $this->assertSame(MonitorStatus::Down, $monitor->state()->firstOrFail()->status);
    }

    public function test_a_deferred_write_that_keeps_failing_never_blocks_outcomes_and_is_dropped_after_ten_attempts(): void
    {
        $monitor = Monitor::factory()->for($this->ws)->http()->create();
        $buffer = new OutcomeBuffer($this->app->make(ResultRecorder::class), $this->clock, reconnect: fn () => null);
        $attempts = 0;
        $dropped = 0;
        $buffer->defer(function () use (&$attempts): void {
            $attempts++;
            throw new \RuntimeException('Deterministic failure');
        }, function () use (&$dropped): void {
            $dropped++;
        });

        $buffer->push(MonitorSpec::fromModel($monitor), CheckOutcome::up(12), $this->clock->now(), null, fn () => null);
        $this->assertTrue($buffer->flush(), 'outcomes are written although the deferred write failed');
        $this->assertSame(1, CheckResult::where('monitor_id', $monitor->id)->count());
        $this->assertSame(1, $attempts);

        for ($i = 0; $i < 12; $i++) {
            $this->clock->advance(1);
            $this->assertTrue($buffer->flush());
        }
        $this->assertSame(10, $attempts, 'retried with each flush, then dropped');
        $this->assertSame(1, $dropped);
        $this->assertNull($buffer->outages()->failingSince(), 'a deferred write is no outcome write');
    }

    public function test_a_reanchoring_that_keeps_failing_holds_back_deadlines_only_until_it_is_dropped(): void
    {
        $monitor = $this->push(['retries' => 0], grace: 30);
        $this->clock->advance(3600);
        $t1 = $this->clock->now();
        $evaluator = new class($this->app->make(DatabaseDeadlineEvaluator::class)) implements DeadlineEvaluator
        {
            public function __construct(private readonly DatabaseDeadlineEvaluator $inner) {}

            public function evaluate(MonitorSpec $spec, CarbonImmutable $now): array
            {
                return $this->inner->evaluate($spec, $now);
            }

            public function evaluateMany(array $specs, CarbonImmutable $now): array
            {
                return $this->inner->evaluateMany($specs, $now);
            }

            public function reanchor(array $specs, ?CarbonImmutable $from, CarbonImmutable $to): int
            {
                throw new \RuntimeException('Deterministic failure');
            }
        };

        [$stop, $run] = $this->startScheduler($evaluator);
        $this->clock->advance(40);
        $this->stopScheduler($stop, $run);

        // The start re-anchoring is dropped after ten flushes; the deadline it held back is then reported.
        $result = CheckResult::where('monitor_id', $monitor->id)->sole();
        $this->assertSame(MonitorStatus::Down, $result->status);
        $this->assertLessThanOrEqual($t1->getTimestamp() + 25, CarbonImmutable::instance($result->checked_at)->getTimestamp());
    }

    public function test_a_database_error_while_recording_one_by_one_starts_the_failure_at_that_deadline(): void
    {
        $monitor = $this->push(['retries' => 0], grace: 30);
        $other = $this->push(['retries' => 0], grace: 30);
        $t0 = $this->clock->now();
        $buffer = new OutcomeBuffer($this->app->make(ResultRecorder::class), $this->clock, reconnect: fn () => null);
        $inserts = 0;
        DB::connection()->beforeExecuting(function (string $query) use (&$inserts): void {
            if (str_starts_with($query, 'insert into') && str_contains($query, 'check_results')) {
                // The batch fails three times with an error that is no database error, so the buffer records
                // its outcomes one by one; then the database fails.
                if (++$inserts <= 3) {
                    throw new \RuntimeException('Unstorable outcome');
                }
                throw new QueryException('sqlite', $query, [], new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'));
            }
        });

        $this->clock->advance(95);
        $buffer->push(MonitorSpec::fromModel($monitor), CheckOutcome::down('No heartbeat')->forDeadline(new Deadline($t0->addSeconds(80), $t0, null, 0)), $this->clock->now(), null, fn () => null);
        $buffer->push(MonitorSpec::fromModel($other), CheckOutcome::down('No heartbeat')->forDeadline(new Deadline($t0->addSeconds(70), $t0, null, 0)), $this->clock->now(), null, fn () => null);
        $this->assertFalse($buffer->flush());
        $this->assertFalse($buffer->flush());
        $this->assertNull($buffer->outages()->holdingSince($this->clock->now()), 'no database error yet');
        $this->assertFalse($buffer->flush());

        $this->assertSame(4, $inserts);
        $this->assertSame(2, $buffer->count(), 'both outcomes stay queued');
        $this->assertSame($t0->getTimestamp() + 80, $buffer->outages()->failingSince()?->getTimestamp(), 'the deadline of the outcome whose write failed');
    }

    /**
     * Makes deadline reads, state and result writes, heartbeats and monitor polls fail while the time since
     * $t0 is inside one of the given [from, until) ranges, passed as pairs of offsets in seconds.
     */
    private function failEverythingBetween(CarbonImmutable $t0, int ...$bounds): void
    {
        DB::connection()->beforeExecuting(function (string $query) use ($t0, $bounds): void {
            $elapsed = $this->clock->now()->getTimestamp() - $t0->getTimestamp();
            $inside = false;
            for ($i = 0; $i + 1 < count($bounds); $i += 2) {
                $inside = $inside || ($elapsed >= $bounds[$i] && $elapsed < $bounds[$i + 1]);
            }
            $lower = strtolower($query);
            $touched = $this->isDeadlineRead($query)
                || str_contains($query, 'check_results')
                || str_contains($query, 'check_workers')
                || (str_starts_with($lower, 'update') && str_contains($query, 'monitor_states'))
                || (str_starts_with($lower, 'select') && str_contains($query, 'monitors'));
            if ($inside && $touched) {
                throw new QueryException('sqlite', $query, [], new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'));
            }
        });
    }

    private function isDeadlineRead(string $query): bool
    {
        return str_starts_with(strtolower($query), 'select') && str_contains($query, 'deadline_anchor_at') && ! str_contains($query, 'consecutive_failures');
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

        // One more read is the start re-anchoring at the first flush, itself one batched read.
        $this->assertSame(3, $reads, 'the shared deadline is evaluated in one read');
        foreach ($monitors as $monitor) {
            $this->assertSame(MonitorStatus::Down, $monitor->state()->firstOrFail()->status);
        }
    }

    /**
     * @return array{0: DeferredCancellation, 1: Future<void>}
     */
    private function startScheduler(?DeadlineEvaluator $deadlines = null, ?OutcomeBuffer $buffer = null): array
    {
        $scheduler = new Scheduler(
            shard: 0,
            shards: 1,
            loader: new MonitorLoader($this->clock),
            buffer: $buffer ?? new OutcomeBuffer($this->app->make(ResultRecorder::class), $this->clock, reconnect: fn () => null),
            heartbeat: new Heartbeat($this->clock, 0, 1),
            checkers: $this->app->make(CheckerRegistry::class),
            deadlines: $deadlines ?? $this->app->make(DeadlineEvaluator::class),
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
