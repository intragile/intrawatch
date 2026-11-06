<?php

namespace Tests\Feature\Monitoring;

use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BenchCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
    }

    public function test_bench_checks_the_local_target_reports_drift_and_cleans_up(): void
    {
        $exit = Artisan::call('intrawatch:bench', ['--monitors' => '40', '--interval' => '20', '--duration' => '4']);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertMatchesRegularExpression('/drift p50 \(ms\)\s*\|\s*\d+/', $output);
        $this->assertMatchesRegularExpression('/checks not up\s*\|\s*0 /', $output);
        $this->assertStringContainsString('max flush (ms)', $output);
        $this->assertSame(0, Workspace::query()->count());
        $this->assertSame(0, DB::table('monitors')->count());
        $this->assertSame(0, DB::table('check_results')->count());
        $this->assertSame(0, DB::table('check_workers')->count());
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
}
