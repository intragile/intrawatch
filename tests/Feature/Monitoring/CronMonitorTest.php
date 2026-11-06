<?php

namespace Tests\Feature\Monitoring;

use Amp\DeferredCancellation;
use App\Enums\MonitorStatus;
use App\Enums\WorkspaceRole;
use App\Models\CheckResult;
use App\Models\Monitor;
use App\Models\User;
use App\Models\Workspace;
use App\Monitoring\Checks\CheckerRegistry;
use App\Monitoring\Engine\Availability;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeClock;
use Tests\TestCase;

use function Amp\async;

class CronMonitorTest extends TestCase
{
    use RefreshDatabase;

    private FakeClock $clock;

    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FakeClock(CarbonImmutable::parse('2026-11-06 00:30:00', 'UTC'));
        $this->ws = Workspace::factory()->create();
    }

    public function test_cron_start_finish_duration_and_exit_code(): void
    {
        $monitor = $this->cron('0 * * * *');
        $url = "/api/v1/push/{$monitor->push_token}";

        $this->getJson("{$url}/start")->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame(0, CheckResult::count(), 'a start records nothing');
        $startedAt = $monitor->state()->firstOrFail()->run_started_at;
        $this->assertNotNull($startedAt);
        $this->assertTrue($this->clock->now()->equalTo($startedAt));

        $this->clock->advance(90);
        $this->getJson($url)->assertOk();
        $result = CheckResult::where('monitor_id', $monitor->id)->sole();
        $this->assertSame(MonitorStatus::Up->value, $result->getRawOriginal('status'));
        $this->assertSame(90_000, $result->latency_ms);
        $this->assertNull($monitor->state()->firstOrFail()->run_started_at);

        $this->getJson("{$url}/0")->assertOk();
        $this->assertNull(CheckResult::where('monitor_id', $monitor->id)->latest('id')->firstOrFail()->latency_ms, 'no start, no duration');

        $this->getJson("{$url}/3")->assertOk();
        $last = CheckResult::where('monitor_id', $monitor->id)->latest('id')->firstOrFail();
        $this->assertSame(MonitorStatus::Down->value, $last->getRawOriginal('status'), 'a failure skips the retries');
        $this->assertSame('Exit code 3', $last->message);

        $this->getJson("{$url}/fail")->assertOk();
        $this->assertSame('Reported failure', CheckResult::where('monitor_id', $monitor->id)->latest('id')->firstOrFail()->message);

        $this->getJson("{$url}/256")->assertNotFound();
    }

    public function test_cron_missed_run_and_max_runtime(): void
    {
        // Created at 00:30; the 01:00 run is due by 01:05.
        $monitor = $this->cron('0 * * * *', grace: 300, maxRuntime: 600);

        $this->clock->advance(35 * 60);
        [$outcome] = $this->evaluate($monitor);
        $this->assertNull($outcome, '01:05:00 is still within the grace period');

        $this->clock->advance(1);
        [$outcome] = $this->evaluate($monitor);
        $this->assertNotNull($outcome);
        $this->assertSame('Missed run expected at 2026-11-06T01:00:00+00:00', $outcome->message);
        $this->record($monitor, $outcome);
        $this->assertSame(MonitorStatus::Down, $monitor->state()->firstOrFail()->status, 'a missed run skips the retries');

        $this->clock->advance(60);
        [$outcome] = $this->evaluate($monitor);
        $this->assertNull($outcome, 'one missed run is reported once');

        // A run starts at 02:00 and never finishes.
        $this->clock->advance(CarbonImmutable::parse('2026-11-06 02:00:00', 'UTC')->getTimestamp() - $this->clock->now()->getTimestamp());
        $this->getJson("/api/v1/push/{$monitor->push_token}/start")->assertOk();
        $this->clock->advance(600);
        [$outcome] = $this->evaluate($monitor);
        $this->assertNull($outcome, 'exactly the maximum runtime is allowed');

        $this->clock->advance(1);
        [$outcome] = $this->evaluate($monitor);
        $this->assertNotNull($outcome);
        $this->assertSame('Run exceeded 600 s', $outcome->message);
        $this->assertTrue($outcome->final);
        $this->record($monitor, $outcome);

        $this->clock->advance(60);
        [$outcome] = $this->evaluate($monitor);
        $this->assertNull($outcome, 'one overrun is reported once');
    }

    public function test_a_finish_after_the_evaluation_cancels_the_missed_run_and_the_overrun(): void
    {
        $monitor = $this->cron('0 * * * *', grace: 300, maxRuntime: 600);

        $this->clock->advance(35 * 60 + 1);
        [$missed] = $this->evaluate($monitor);
        $this->assertNotNull($missed);
        $this->getJson("/api/v1/push/{$monitor->push_token}/start")->assertOk();
        $this->record($monitor, $missed);
        $this->assertSame(0, CheckResult::where('monitor_id', $monitor->id)->count(), 'a run started meanwhile');

        $this->clock->advance(601);
        [$overrun] = $this->evaluate($monitor);
        $this->assertNotNull($overrun);
        $this->getJson("/api/v1/push/{$monitor->push_token}")->assertOk();
        $this->record($monitor, $overrun);

        $this->assertSame([MonitorStatus::Up], CheckResult::where('monitor_id', $monitor->id)->get()->pluck('status')->all());
    }

    public function test_resume_clears_a_stale_run(): void
    {
        $editor = User::factory()->memberOf($this->ws, WorkspaceRole::Editor)->create();
        $monitor = $this->cron('0 * * * *', grace: 300, maxRuntime: 600);
        $url = "/api/v1/workspaces/{$this->ws->slug}/monitors/{$monitor->id}";

        $this->getJson("/api/v1/push/{$monitor->push_token}/start")->assertOk();
        $this->actingAs($editor)->postJson("{$url}/pause")->assertOk();
        $this->clock->advance(3 * 3600);
        $this->actingAs($editor)->postJson("{$url}/resume")->assertOk();

        [$outcome] = $this->evaluate($monitor);
        $this->assertNull($outcome);
        $this->assertNull($monitor->state()->firstOrFail()->run_started_at);
    }

    public function test_cron_respects_timezone(): void
    {
        $tz = 'Europe/Budapest';
        $at = fn (string $after, string $schedule = '0 2 * * *'): ?string => DatabaseDeadlineEvaluator::expectedRun($schedule, $tz, CarbonImmutable::parse($after))?->format(DATE_ATOM);

        // Spring forward (29 March 2026): 02:00 to 02:59 do not exist; cron daemons run those jobs at the jump.
        $this->assertSame('2026-03-29T03:00:00+02:00', $at('2026-03-28T12:00:00Z'));
        $this->assertSame('2026-03-29T03:00:00+02:00', $at('2026-03-28T12:00:00Z', '30 2 * * *'));
        $this->assertSame('2026-03-30T02:30:00+02:00', $at('2026-03-29T01:00:05Z', '30 2 * * *'), 'a run at the jump satisfies the skipped 02:30');
        $this->assertSame('2026-03-30T02:00:00+02:00', $at('2026-03-29T01:00:05Z', '*/15 2 * * *'));

        // Fall back (25 October 2026): 02:00 to 02:59 happen twice; each wall-clock time is expected once.
        $this->assertSame('2026-10-25T02:00:00+02:00', $at('2026-10-24T12:00:00Z'));
        $this->assertSame('2026-10-26T02:00:00+01:00', $at('2026-10-25T00:00:30Z'));
        $this->assertSame('2026-10-26T02:00:00+01:00', $at('2026-10-25T00:30:30Z', '0,30 2 * * *'));
        $this->assertSame('2026-10-26T02:00:00+01:00', $at('2026-10-25T00:45:30Z', '*/15 2 * * *'));
        $this->assertSame('2026-10-25T03:00:00+01:00', $at('2026-10-25T01:10:00Z', '*/15 * * * *'), 'the repeated hour is not expected again');
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: list<string>, 3: list<string>, 4: ?string}>
     */
    public static function dstScenarios(): iterable
    {
        foreach (['0 2 * * *', '30 2 * * *', '0,30 2 * * *', '*/15 2 * * *'] as $schedule) {
            // Spring forward: every run of the skipped hour happens once, at 03:00 CEST (01:00Z).
            yield "spring {$schedule} ran" => [$schedule, '2026-03-28T12:00:00Z', ['2026-03-29T01:00:05Z'], ['2026-03-29T01:05:01Z', '2026-03-29T02:40:00Z'], null];
            yield "spring {$schedule} did not run" => [$schedule, '2026-03-28T12:00:00Z', [], ['2026-03-29T01:05:01Z'], 'Missed run expected at 2026-03-29T03:00:00+02:00'];
        }

        // Fall back: the runs happen in the first pass (CEST) only.
        yield 'fall 0 2 ran' => ['0 2 * * *', '2026-10-24T12:00:00Z', ['2026-10-25T00:00:05Z'], ['2026-10-25T01:05:01Z', '2026-10-25T01:35:01Z'], null];
        yield 'fall 0 2 did not run' => ['0 2 * * *', '2026-10-24T12:00:00Z', [], ['2026-10-25T00:05:01Z'], 'Missed run expected at 2026-10-25T02:00:00+02:00'];
        yield 'fall 30 2 ran' => ['30 2 * * *', '2026-10-24T12:00:00Z', ['2026-10-25T00:30:05Z'], ['2026-10-25T01:35:01Z'], null];
        yield 'fall 30 2 did not run' => ['30 2 * * *', '2026-10-24T12:00:00Z', [], ['2026-10-25T00:35:01Z'], 'Missed run expected at 2026-10-25T02:30:00+02:00'];
        yield 'fall 0,30 2 ran' => ['0,30 2 * * *', '2026-10-24T12:00:00Z', ['2026-10-25T00:00:05Z', '2026-10-25T00:30:05Z'], ['2026-10-25T01:05:01Z', '2026-10-25T01:35:01Z'], null];
        yield 'fall 0,30 2 skipped 02:30' => ['0,30 2 * * *', '2026-10-24T12:00:00Z', ['2026-10-25T00:00:05Z'], ['2026-10-25T00:35:01Z'], 'Missed run expected at 2026-10-25T02:30:00+02:00'];
        $quarters = ['2026-10-25T00:00:05Z', '2026-10-25T00:15:05Z', '2026-10-25T00:30:05Z', '2026-10-25T00:45:05Z'];
        yield 'fall */15 2 ran' => ['*/15 2 * * *', '2026-10-24T12:00:00Z', $quarters, ['2026-10-25T01:05:01Z', '2026-10-25T01:20:01Z', '2026-10-25T01:50:01Z'], null];
        yield 'fall */15 2 skipped 02:45' => ['*/15 2 * * *', '2026-10-24T12:00:00Z', array_slice($quarters, 0, 3), ['2026-10-25T00:50:01Z'], 'Missed run expected at 2026-10-25T02:45:00+02:00'];
    }

    /**
     * @param  list<string>  $pings
     * @param  list<string>  $evaluations
     */
    #[DataProvider('dstScenarios')]
    public function test_dst_days_never_give_a_false_miss_and_still_catch_a_real_one(string $schedule, string $createdAt, array $pings, array $evaluations, ?string $expected): void
    {
        $this->clock = new FakeClock(CarbonImmutable::parse($createdAt));
        $monitor = $this->cron($schedule, 'Europe/Budapest', grace: 300);

        foreach ($pings as $ping) {
            $this->moveTo($ping);
            $this->getJson("/api/v1/push/{$monitor->push_token}")->assertOk();
        }

        $messages = [];
        foreach ($evaluations as $evaluation) {
            $this->moveTo($evaluation);
            [$outcome] = $this->evaluate($monitor);
            $messages[] = $outcome?->message;
        }

        $this->assertSame($expected === null ? array_fill(0, count($evaluations), null) : [$expected], $messages);
    }

    public function test_an_early_ping_counts_for_the_upcoming_run(): void
    {
        $this->assertSame('2026-11-06T02:00:00+00:00', DatabaseDeadlineEvaluator::expectedRun('0 * * * *', 'UTC', CarbonImmutable::parse('2026-11-06T00:59:55Z'))?->format(DATE_ATOM));
        $this->assertSame('2026-11-06T01:00:00+00:00', DatabaseDeadlineEvaluator::expectedRun('0 * * * *', 'UTC', CarbonImmutable::parse('2026-11-06T00:58:59Z'))?->format(DATE_ATOM), 'more than 60 s early is the previous run');
        $this->assertSame('2026-11-06T00:02:00+00:00', DatabaseDeadlineEvaluator::expectedRun('* * * * *', 'UTC', CarbonImmutable::parse('2026-11-06T00:00:31Z'))?->format(DATE_ATOM), 'at most half the gap between runs');
        // The gap that counts is the one the report falls into: 00:00 to 00:01, not the hour after 00:01.
        $this->assertSame('2026-11-06T00:01:00+00:00', DatabaseDeadlineEvaluator::expectedRun('0,1 * * * *', 'UTC', CarbonImmutable::parse('2026-11-06T00:00:25Z'))?->format(DATE_ATOM), 'the 00:00 run finishing at 00:00:25');
        $this->assertSame('2026-11-06T01:00:00+00:00', DatabaseDeadlineEvaluator::expectedRun('0,1 * * * *', 'UTC', CarbonImmutable::parse('2026-11-06T00:00:31Z'))?->format(DATE_ATOM), 'within 30 s of 00:01');
        $this->assertSame('2026-11-06T00:01:00+00:00', DatabaseDeadlineEvaluator::expectedRun('0,1 * * * *', 'UTC', CarbonImmutable::parse('2026-11-05T23:59:30Z'))?->format(DATE_ATOM), 'within 60 s before 00:00, the hour before');

        $monitor = $this->cron('0 * * * *', grace: 300);
        $this->moveTo('2026-11-06T00:59:55Z');
        $this->getJson("/api/v1/push/{$monitor->push_token}")->assertOk();

        $this->moveTo('2026-11-06T01:05:01Z');
        [$outcome] = $this->evaluate($monitor);
        $this->assertNull($outcome, 'the ping 5 s early is the 01:00 run');

        $this->moveTo('2026-11-06T02:05:01Z');
        [$outcome] = $this->evaluate($monitor);
        $this->assertSame('Missed run expected at 2026-11-06T02:00:00+00:00', $outcome?->message);
    }

    public function test_a_finish_never_clears_a_run_that_started_meanwhile(): void
    {
        $monitor = $this->cron('* * * * *', grace: 30, maxRuntime: 600);
        $this->getJson("/api/v1/push/{$monitor->push_token}/start")->assertOk();
        $this->clock->advance(90);
        $restartedAt = $this->clock->now();

        $done = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$done, $monitor, $restartedAt): void {
            if (! $done && str_starts_with($query, 'insert into') && str_contains($query, 'check_results')) {
                $done = true;
                // The next run's start commits between the finish reading the run and clearing it.
                DB::table('monitor_states')->where('monitor_id', $monitor->id)->update(['run_started_at' => $restartedAt]);
            }
        });

        $this->getJson("/api/v1/push/{$monitor->push_token}")->assertOk();

        $startedAt = $monitor->state()->firstOrFail()->run_started_at;
        $this->assertNotNull($startedAt, 'the new run is still in progress');
        $this->assertTrue($restartedAt->equalTo($startedAt));
    }

    public function test_runs_missed_before_the_worker_started_are_not_reported(): void
    {
        $monitor = $this->cron('0 * * * *', grace: 300);
        $this->moveTo('2026-11-06T03:30:00Z');

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
        $this->clock->advance(60);
        $this->assertSame(0, CheckResult::where('monitor_id', $monitor->id)->count(), 'the 01:00 to 03:00 runs fell before the worker started');

        $this->moveTo('2026-11-06T04:06:30Z');
        $stop->cancel();
        $this->clock->advance(12);
        $run->await();

        $this->assertSame(['Missed run expected at 2026-11-06T04:00:00+00:00'], CheckResult::where('monitor_id', $monitor->id)->pluck('message')->all());
    }

    public function test_a_cron_run_missed_before_an_outage_is_still_reported(): void
    {
        // Created at 00:30; the 01:00 run is due by 01:05. The database was out from 01:10 to 01:50.
        $monitor = $this->cron('0 * * * *', grace: 300);
        $outage = new Availability(
            CarbonImmutable::parse('2026-11-06T00:00:00Z'),
            CarbonImmutable::parse('2026-11-06T01:10:00Z'),
            CarbonImmutable::parse('2026-11-06T01:50:00Z'),
        );

        $this->moveTo('2026-11-06T01:51:00Z');
        [$outcome] = $this->evaluate($monitor, $outage);
        $this->assertSame('Missed run expected at 2026-11-06T01:00:00+00:00', $outcome?->message);

        // A run due inside the outage is not reported; the first one after it is expected.
        $inside = new Availability(
            CarbonImmutable::parse('2026-11-06T00:00:00Z'),
            CarbonImmutable::parse('2026-11-06T01:00:00Z'),
            CarbonImmutable::parse('2026-11-06T01:50:00Z'),
        );
        [$outcome, $next] = $this->evaluate($monitor, $inside);
        $this->assertNull($outcome);
        $this->assertSame('2026-11-06T01:52:00+00:00', $next->format(DATE_ATOM), 'polled every interval until 02:05:01');
    }

    private function moveTo(string $at): void
    {
        $this->clock->advance(CarbonImmutable::parse($at)->getTimestamp() - $this->clock->now()->getTimestamp());
    }

    public function test_cron_timezone_defaults_to_the_app_timezone(): void
    {
        config(['app.timezone' => 'UTC']);
        $monitor = Monitor::factory()->for($this->ws)->cron('0 1 * * *')->create([
            'interval_seconds' => 60,
            'config' => ['schedule' => '0 1 * * *', 'timezone' => null, 'grace_seconds' => 60],
        ]);

        $this->clock->advance(30 * 60 + 61);
        [$outcome] = $this->evaluate($monitor);
        $this->assertNotNull($outcome);
        $this->assertSame('Missed run expected at 2026-11-06T01:00:00+00:00', $outcome->message);
    }

    private function cron(string $schedule, string $timezone = 'UTC', int $grace = 300, ?int $maxRuntime = null): Monitor
    {
        return Monitor::factory()->for($this->ws)->cron($schedule, $timezone, $grace)->create([
            'interval_seconds' => 60,
            'retries' => 2,
            'config' => ['schedule' => $schedule, 'timezone' => $timezone, 'grace_seconds' => $grace, 'max_runtime_seconds' => $maxRuntime],
        ]);
    }

    /**
     * @return array{0: ?CheckOutcome, 1: CarbonImmutable}
     */
    private function evaluate(Monitor $monitor, ?Availability $availability = null): array
    {
        return $this->app->make(DeadlineEvaluator::class)->evaluate(MonitorSpec::fromModel($monitor->fresh() ?? $monitor), $this->clock->now(), $availability);
    }

    private function record(Monitor $monitor, CheckOutcome $outcome): void
    {
        $this->app->make(ResultRecorder::class)->record([[MonitorSpec::fromModel($monitor->fresh() ?? $monitor), $outcome, $this->clock->now(), null]]);
    }
}
