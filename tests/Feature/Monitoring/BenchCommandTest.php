<?php

namespace Tests\Feature\Monitoring;

use App\Console\Commands\BenchCommand;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Revolt\EventLoop;
use Tests\TestCase;

class BenchCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_bench_checks_the_local_target_reports_drift_and_cleans_up(): void
    {
        $exit = Artisan::call('intrawatch:bench', ['--monitors' => '40', '--interval' => '20', '--duration' => '4']);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertMatchesRegularExpression('/drift p50 \(ms\)\s*\|\s*\d+/', $output);
        $this->assertMatchesRegularExpression('/checks not up\s*\|\s*0 /', $output);
        $this->assertStringContainsString('max flush (ms)', $output);
        $this->assertStringContainsString('expected checks', $output);
        $this->assertBenchLeftNothing();
    }

    public function test_an_interrupted_bench_cleans_up(): void
    {
        $this->assertTrue(function_exists('posix_kill'), 'the test needs the posix extension');
        EventLoop::delay(1.5, static function (): void {
            posix_kill(getmypid() ?: 0, SIGINT);
        });

        $started = microtime(true);
        $exit = Artisan::call('intrawatch:bench', ['--monitors' => '40', '--interval' => '20', '--duration' => '60']);

        $this->assertSame(1, $exit);
        $this->assertLessThan(20, microtime(true) - $started, 'the interrupt ends the run');
        $this->assertStringContainsString('Interrupted', Artisan::output());
        $this->assertBenchLeftNothing();
    }

    public function test_bench_never_touches_the_heartbeat_of_a_real_shard(): void
    {
        $stale = CarbonImmutable::now()->subMinutes(10)->startOfSecond();
        DB::table('check_workers')->insert([
            'shard' => 0, 'shards' => 4, 'hostname' => 'worker-a', 'pid' => 100, 'started_at' => $stale->subHour(),
            'last_seen_at' => $stale, 'monitors' => 7, 'checks_last_minute' => 0, 'max_drift_ms_last_minute' => 0, 'buffered' => 0,
        ]);

        $this->assertSame(0, Artisan::call('intrawatch:bench', ['--monitors' => '10', '--interval' => '20', '--duration' => '2']));

        $this->assertSame(1, DB::table('check_workers')->where([
            'shard' => 0, 'shards' => 4, 'monitors' => 7, 'hostname' => 'worker-a',
        ])->count(), 'the stale row is left exactly as it was');
    }

    public function test_bench_refuses_a_database_with_other_workspaces_unless_forced(): void
    {
        $existing = Workspace::query()->create(['name' => 'Production', 'slug' => 'production', 'timezone' => 'UTC']);

        $this->assertSame(1, Artisan::call('intrawatch:bench', ['--monitors' => '1', '--duration' => '1']));
        $this->assertStringContainsString('--force', Artisan::output());
        $this->assertSame(1, Workspace::query()->count());

        $this->assertSame(0, Artisan::call('intrawatch:bench', ['--monitors' => '40', '--interval' => '20', '--duration' => '4', '--force' => true]), Artisan::output());
        $this->assertSame([$existing->id], Workspace::query()->pluck('id')->all());
        $this->assertSame(0, DB::table('monitors')->count());
    }

    public function test_bench_refuses_to_run_next_to_a_live_worker(): void
    {
        $now = CarbonImmutable::now();
        DB::table('check_workers')->insert([
            'shard' => 0, 'shards' => 1, 'hostname' => 'worker-a', 'pid' => 100, 'started_at' => $now->subHour(),
            'last_seen_at' => $now->subSeconds(5), 'monitors' => 0, 'checks_last_minute' => 0, 'max_drift_ms_last_minute' => 0, 'buffered' => 0,
        ]);

        $this->assertSame(1, Artisan::call('intrawatch:bench', ['--monitors' => '1', '--duration' => '1']));
        $this->assertStringContainsString('separate container', Artisan::output());
        $this->assertSame(0, Workspace::query()->count());
    }

    public function test_bench_rejects_invalid_options(): void
    {
        $this->assertSame(2, Artisan::call('intrawatch:bench', ['--monitors' => '0']));
        $this->assertSame(2, Artisan::call('intrawatch:bench', ['--interval' => '5']));
    }

    public function test_verdict_fails_on_drift_missing_checks_or_failed_checks(): void
    {
        $this->assertSame([], BenchCommand::verdict(checks: 3000, expected: 3000, failed: 0, maxDriftMs: 1999));
        $this->assertSame([], BenchCommand::verdict(checks: 2850, expected: 3000, failed: 0, maxDriftMs: 10));
        $this->assertCount(1, BenchCommand::verdict(checks: 3000, expected: 3000, failed: 0, maxDriftMs: 2000));
        $this->assertCount(1, BenchCommand::verdict(checks: 2849, expected: 3000, failed: 0, maxDriftMs: 10));
        $this->assertCount(1, BenchCommand::verdict(checks: 3000, expected: 3000, failed: 1, maxDriftMs: 10));
    }

    private function assertBenchLeftNothing(): void
    {
        $this->assertSame(0, Workspace::query()->count());
        $this->assertSame(0, DB::table('monitors')->count());
        $this->assertSame(0, DB::table('check_results')->count());
        $this->assertSame(0, DB::table('monitor_events')->count());
        $this->assertSame(0, DB::table('jobs')->count(), 'no broadcast jobs are left behind');
        $this->assertSame(0, DB::table('check_workers')->count());
    }
}
