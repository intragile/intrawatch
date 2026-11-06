<?php

namespace Tests\Feature\Monitoring;

use App\Enums\WorkspaceRole;
use App\Jobs\PurgeMonitorData;
use App\Models\Monitor;
use App\Models\User;
use App\Models\Workspace;
use App\Monitoring\Engine\RetentionPruner;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class RetentionTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC');
        CarbonImmutable::setTestNow($this->now);
        config(['monitoring.retention.chunk_size' => 2, 'monitoring.retention.chunk_sleep_ms' => 0]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function insertResult(int $monitorId, CarbonImmutable $at): void
    {
        DB::table('check_results')->insert([
            'monitor_id' => $monitorId,
            'status' => 'up',
            'latency_ms' => 10,
            'checked_at' => $at->format('Y-m-d H:i:s'),
            'bucket_minute' => intdiv($at->getTimestamp(), 60) * 60,
        ]);
    }

    private function insertRollup(int $monitorId, string $bucket, int $start): void
    {
        DB::table('check_rollups')->insert([
            'monitor_id' => $monitorId, 'bucket' => $bucket, 'bucket_start' => $start,
            'total' => 1, 'up' => 1, 'down' => 0, 'degraded' => 0, 'pending' => 0, 'maintenance' => 0,
            'latency_min' => 10, 'latency_max' => 10, 'latency_avg' => 10, 'latency_sum' => 10, 'latency_count' => 1,
        ]);
    }

    private function insertEvent(int $workspaceId, int $monitorId, CarbonImmutable $at): void
    {
        DB::table('monitor_events')->insert([
            'workspace_id' => $workspaceId, 'monitor_id' => $monitorId, 'kind' => 'status_changed',
            'from_status' => 'up', 'to_status' => 'down', 'message' => 'Down', 'created_at' => $at->format('Y-m-d H:i:s'),
        ]);
    }

    private function insertLookup(string $domain, CarbonImmutable $checkedAt): void
    {
        DB::table('domain_lookups')->insert([
            'domain' => $domain, 'source' => 'rdap', 'status' => 'ok', 'checked_at' => $checkedAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_prune_respects_each_retention_and_chunks(): void
    {
        $monitor = Monitor::factory()->create();
        $m = $monitor->id;
        $rawCutoff = $this->now->subHours(24);
        $minuteCutoff = $this->now->subHours(48)->getTimestamp();
        $hourCutoff = $this->now->subDays(90)->getTimestamp();
        $dayCutoff = $this->now->subDays(730);

        foreach ([3, 2, 1] as $seconds) {
            $this->insertResult($m, $rawCutoff->subSeconds($seconds));
        }
        $this->insertResult($m, $rawCutoff);
        $this->insertResult($m, $rawCutoff->addSecond());

        // Each bucket is pruned by its own retention only: a minute row older than 48 h goes, an hour row of
        // the same age stays.
        $this->insertRollup($m, 'minute', $minuteCutoff - 1);
        $this->insertRollup($m, 'minute', $minuteCutoff);
        $this->insertRollup($m, 'hour', $minuteCutoff - 1);
        $this->insertRollup($m, 'hour', $hourCutoff - 1);
        $this->insertRollup($m, 'hour', $hourCutoff);
        $this->insertRollup($m, 'day', $hourCutoff - 1);
        $this->insertRollup($m, 'day', $dayCutoff->getTimestamp() - 1);
        $this->insertRollup($m, 'day', $dayCutoff->getTimestamp());

        $this->insertEvent($monitor->workspace_id, $m, $dayCutoff->subSecond());
        $this->insertEvent($monitor->workspace_id, $m, $dayCutoff);

        $this->insertLookup('old.example', $this->now->subDays(30)->subSecond());
        $this->insertLookup('fresh.example', $this->now->subDays(30));

        $deletes = 0;
        DB::listen(function (QueryExecuted $query) use (&$deletes): void {
            if (str_starts_with(strtolower($query->sql), 'delete from "check_results"')
                || str_starts_with(strtolower($query->sql), 'delete from `check_results`')) {
                $deletes++;
            }
        });

        app(RetentionPruner::class)->run($this->now);

        $this->assertSame(
            [$rawCutoff->format('Y-m-d H:i:s'), $rawCutoff->addSecond()->format('Y-m-d H:i:s')],
            DB::table('check_results')->orderBy('id')->pluck('checked_at')->map(fn (mixed $v): string => is_string($v) ? substr($v, 0, 19) : '')->all(),
        );
        $this->assertGreaterThanOrEqual(2, $deletes, 'Three expired rows with a chunk size of 2 need at least two deletes');

        $remaining = DB::table('check_rollups')->orderBy('id')->get(['bucket', 'bucket_start'])
            ->map(fn (object $r): string => (is_string($r->bucket) ? $r->bucket : '').'@'.(is_numeric($r->bucket_start) ? (int) $r->bucket_start : ''))->all();
        $this->assertSame([
            'minute@'.$minuteCutoff,
            'hour@'.($minuteCutoff - 1),
            'hour@'.$hourCutoff,
            'day@'.($hourCutoff - 1),
            'day@'.$dayCutoff->getTimestamp(),
        ], $remaining);

        $this->assertSame(1, DB::table('monitor_events')->count());
        $this->assertSame(['fresh.example'], DB::table('domain_lookups')->pluck('domain')->all());
    }

    public function test_prune_sleeps_between_chunks(): void
    {
        config(['monitoring.retention.chunk_sleep_ms' => 50]);
        Sleep::fake();
        $slept = [];
        Sleep::whenFakingSleep(function (CarbonInterval $duration) use (&$slept): void {
            $slept[] = (int) $duration->totalMilliseconds;
        });

        $monitor = Monitor::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->insertResult($monitor->id, $this->now->subDays(2)->addSeconds($i));
        }

        app(RetentionPruner::class)->run($this->now);

        $this->assertSame(0, DB::table('check_results')->count());
        $this->assertNotEmpty($slept);
        $this->assertSame([50], array_values(array_unique($slept)));
    }

    public function test_prune_command_runs(): void
    {
        $monitor = Monitor::factory()->create();
        $this->insertResult($monitor->id, $this->now->subDays(2));

        $this->assertSame(0, Artisan::call('intrawatch:prune'));

        $this->assertSame(0, DB::table('check_results')->count());
    }

    public function test_deleting_monitor_purges_its_data(): void
    {
        $workspace = Workspace::factory()->create();
        $monitor = Monitor::factory()->create(['workspace_id' => $workspace->id]);
        $other = Monitor::factory()->create(['workspace_id' => $workspace->id]);
        foreach ([$monitor, $other] as $m) {
            for ($i = 0; $i < 5; $i++) {
                $this->insertResult($m->id, $this->now->subMinutes($i));
                $this->insertRollup($m->id, 'minute', $this->now->subMinutes($i)->getTimestamp());
                $this->insertEvent($m->workspace_id, $m->id, $this->now->subMinutes($i));
            }
            $this->insertRollup($m->id, 'hour', $this->now->startOfHour()->getTimestamp());
            $this->insertRollup($m->id, 'day', $this->now->startOfDay()->getTimestamp());
        }
        $editor = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();

        $this->actingAs($editor)
            ->deleteJson("/api/v1/workspaces/{$workspace->slug}/monitors/{$monitor->id}")
            ->assertNoContent();

        foreach (['check_results', 'check_rollups', 'monitor_events'] as $table) {
            $this->assertSame(0, DB::table($table)->where('monitor_id', $monitor->id)->count(), $table);
        }
        $this->assertSame(5, DB::table('check_results')->where('monitor_id', $other->id)->count());
        $this->assertSame(7, DB::table('check_rollups')->where('monitor_id', $other->id)->count());
        $this->assertSame(5, DB::table('monitor_events')->where('monitor_id', $other->id)->count());
    }

    public function test_purge_job_deletes_in_chunks(): void
    {
        $monitor = Monitor::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->insertResult($monitor->id, $this->now->subMinutes($i));
        }
        $deletes = 0;
        DB::listen(function (QueryExecuted $query) use (&$deletes): void {
            if (preg_match('/^delete from ["`]check_results["`]/i', $query->sql) === 1) {
                $deletes++;
            }
        });

        (new PurgeMonitorData($monitor->id))->handle();

        $this->assertSame(0, DB::table('check_results')->count());
        $this->assertSame(3, $deletes);
    }
}
