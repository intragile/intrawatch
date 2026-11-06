<?php

namespace Tests\Feature\Monitoring;

use App\Enums\MonitorStatus;
use App\Enums\WorkspaceRole;
use App\Models\CheckResult;
use App\Models\Monitor;
use App\Models\User;
use App\Models\Workspace;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\DatabaseDeadlineEvaluator;
use App\Monitoring\Engine\DeadlineEvaluator;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Engine\ResultRecorder;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeClock;
use Tests\TestCase;

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
        $cron = new CronExpression('0 2 * * *');
        $tz = 'Europe/Budapest';
        $expected = fn (string $after): CarbonImmutable => CarbonImmutable::instance($cron->getNextRunDate(CarbonImmutable::parse($after), 0, false, $tz));

        // Spring forward (29 March 2026): 02:00 does not exist and the run is expected at 03:00 CEST.
        $spring = $expected('2026-03-28T12:00:00Z');
        $this->assertSame('2026-03-29T03:00:00+02:00', $spring->format(DATE_ATOM));
        $this->assertSame($spring->format(DATE_ATOM), DatabaseDeadlineEvaluator::expectedRun('0 2 * * *', $tz, CarbonImmutable::parse('2026-03-28T12:00:00Z'))?->format(DATE_ATOM));

        // Fall back (25 October 2026): 02:00 happens twice; the first one, 02:00 CEST, is expected.
        $fall = $expected('2026-10-24T12:00:00Z');
        $this->assertSame('2026-10-25T02:00:00+02:00', $fall->format(DATE_ATOM));
        $this->assertSame($fall->format(DATE_ATOM), DatabaseDeadlineEvaluator::expectedRun('0 2 * * *', $tz, CarbonImmutable::parse('2026-10-24T12:00:00Z'))?->format(DATE_ATOM));

        // After the 02:00 CEST run, cron-expression would expect the repeated 02:00 CET too; cron daemons run a
        // fixed-time job once, so the next expected run is the following day.
        $this->assertSame('2026-10-25T02:00:00+01:00', $expected('2026-10-25T00:00:30Z')->format(DATE_ATOM));
        $this->assertSame('2026-10-26T02:00:00+01:00', DatabaseDeadlineEvaluator::expectedRun('0 2 * * *', $tz, CarbonImmutable::parse('2026-10-25T00:00:30Z'))?->format(DATE_ATOM));

        // End to end around the spring-forward day.
        $this->clock->advance(CarbonImmutable::parse('2026-03-28T12:00:00Z')->getTimestamp() - $this->clock->now()->getTimestamp());
        $monitor = $this->cron('0 2 * * *', $tz, grace: 300);

        $this->clock->advance(CarbonImmutable::parse('2026-03-29T00:05:01Z')->getTimestamp() - $this->clock->now()->getTimestamp());
        [$outcome] = $this->evaluate($monitor);
        $this->assertNull($outcome, '02:05 CET would be wrong: the run is expected at 03:00 CEST');

        $this->clock->advance(CarbonImmutable::parse('2026-03-29T01:05:01Z')->getTimestamp() - $this->clock->now()->getTimestamp());
        [$outcome] = $this->evaluate($monitor);
        $this->assertNotNull($outcome);
        $this->assertSame('Missed run expected at 2026-03-29T03:00:00+02:00', $outcome->message);

        // End to end around the fall-back day: a run at 02:00 CEST is not expected again at 02:00 CET.
        $this->clock->advance(CarbonImmutable::parse('2026-10-24T12:00:00Z')->getTimestamp() - $this->clock->now()->getTimestamp());
        $fallMonitor = $this->cron('0 2 * * *', $tz, grace: 300);
        $this->clock->advance(CarbonImmutable::parse('2026-10-25T00:00:30Z')->getTimestamp() - $this->clock->now()->getTimestamp());
        $this->getJson("/api/v1/push/{$fallMonitor->push_token}")->assertOk();
        $this->clock->advance(CarbonImmutable::parse('2026-10-25T01:05:01Z')->getTimestamp() - $this->clock->now()->getTimestamp());
        [$outcome] = $this->evaluate($fallMonitor);
        $this->assertNull($outcome);
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
    private function evaluate(Monitor $monitor): array
    {
        return $this->app->make(DeadlineEvaluator::class)->evaluate(MonitorSpec::fromModel($monitor->fresh() ?? $monitor), $this->clock->now());
    }

    private function record(Monitor $monitor, CheckOutcome $outcome): void
    {
        $this->app->make(ResultRecorder::class)->record([[MonitorSpec::fromModel($monitor->fresh() ?? $monitor), $outcome, $this->clock->now(), null]]);
    }
}
