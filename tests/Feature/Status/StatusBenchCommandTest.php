<?php

namespace Tests\Feature\Status;

use App\Console\Commands\StatusBenchCommand;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatusBenchCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_bench_command_runs_small(): void
    {
        $this->assertSame(0, $this->bench(['--pages' => 3, '--items' => 2, '--requests' => 30]));
        $output = Artisan::output();
        $this->assertStringContainsString('p95', $output);
        $this->assertStringContainsString('warm queries: 0', $output);

        $this->assertSame(0, Workspace::query()->count());
        $this->assertSame(0, DB::table('status_pages')->count());
        $this->assertSame(0, DB::table('monitors')->count());
        $this->assertSame(0, DB::table('check_rollups')->count());
    }

    public function test_keep_leaves_the_workspace(): void
    {
        $this->assertSame(0, $this->bench(['--pages' => 2, '--items' => 1, '--requests' => 4, '--keep' => true]));

        $this->assertSame(1, Workspace::query()->count());
        $this->assertSame(2, DB::table('status_pages')->count());
    }

    public function test_refuses_a_populated_database_unless_forced(): void
    {
        Workspace::factory()->create();

        $this->assertSame(1, $this->bench(['--pages' => 1, '--items' => 1, '--requests' => 2]));
        $this->assertSame(1, Workspace::query()->count());

        $this->assertSame(0, $this->bench(['--pages' => 1, '--items' => 1, '--requests' => 2, '--force' => true]));
        $this->assertSame(1, Workspace::query()->count());
    }

    public function test_rejects_invalid_options(): void
    {
        $this->assertSame(2, $this->bench(['--pages' => 0]));
        $this->assertSame(2, $this->bench(['--items' => 251]));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function bench(array $options): int
    {
        return Artisan::call('intrawatch:bench:status', $options);
    }

    public function test_verdict(): void
    {
        $this->assertSame([], StatusBenchCommand::verdict(99.9, 0, 0));
        $this->assertCount(1, StatusBenchCommand::verdict(100.0, 0, 0));
        $this->assertCount(1, StatusBenchCommand::verdict(1.0, 1, 0));
        $this->assertCount(1, StatusBenchCommand::verdict(1.0, 0, 2));
    }
}
