<?php

namespace Tests\Feature\Monitoring;

use App\Enums\WorkspaceRole;
use App\Jobs\RebuildDayRollups;
use App\Models\CheckRollup;
use App\Models\Monitor;
use App\Models\User;
use App\Models\Workspace;
use App\Monitoring\Engine\RollupBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RollupTest extends TestCase
{
    use RefreshDatabase;

    private const STATUSES = ['up', 'up', 'down', 'degraded', 'pending', 'maintenance', 'up'];

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function monitor(string $timezone = 'UTC'): Monitor
    {
        return Monitor::factory()->create([
            'workspace_id' => Workspace::factory()->create(['timezone' => $timezone])->id,
        ]);
    }

    private function workspaceOf(Monitor $monitor): Workspace
    {
        return Workspace::query()->findOrFail($monitor->workspace_id);
    }

    private function insertResult(Monitor $monitor, CarbonImmutable $at, string $status = 'up', ?int $latency = 100): void
    {
        DB::table('check_results')->insert([
            'monitor_id' => $monitor->id,
            'status' => $status,
            'latency_ms' => $latency,
            'checked_at' => $at->utc()->format('Y-m-d H:i:s'),
            'bucket_minute' => intdiv($at->getTimestamp(), 60) * 60,
        ]);
    }

    private function builder(): RollupBuilder
    {
        return app(RollupBuilder::class);
    }

    private function runUntilCaughtUp(CarbonImmutable $now, int $runs = 6): void
    {
        for ($i = 0; $i < $runs; $i++) {
            $this->builder()->run($now);
        }
    }

    /**
     * @return array<string, int|null>
     */
    private function rollupRow(Monitor $monitor, string $bucket, int $start): array
    {
        $row = CheckRollup::query()->where(['monitor_id' => $monitor->id, 'bucket' => $bucket, 'bucket_start' => $start])->first();
        $this->assertNotNull($row, "Missing {$bucket} rollup at ".gmdate('c', $start));

        return $row->only([
            'total', 'up', 'down', 'degraded', 'pending', 'maintenance',
            'latency_min', 'latency_max', 'latency_avg', 'latency_sum', 'latency_count',
        ]);
    }

    public function test_minute_hour_day_rollups_match_raw(): void
    {
        $monitor = $this->monitor();
        $base = CarbonImmutable::parse('2026-03-10 10:00:00', 'UTC');

        $expected = ['hour' => [], 'day' => ['total' => 0, 'up' => 0, 'down' => 0, 'degraded' => 0, 'pending' => 0, 'maintenance' => 0, 'sum' => 0, 'count' => 0]];
        for ($i = 0; $i < 180; $i++) {
            $status = self::STATUSES[$i % count(self::STATUSES)];
            $latency = $status === 'down' ? null : 10 + ($i * 7) % 300;
            $this->insertResult($monitor, $base->addSeconds($i * 60 + 15), $status, $latency);

            $hour = $base->getTimestamp() + intdiv($i, 60) * 3600;
            $expected['hour'][$hour][] = [$status, $latency];
            $expected['day']['total']++;
            $expected['day'][$status]++;
            if ($latency !== null) {
                $expected['day']['sum'] += $latency;
                $expected['day']['count']++;
            }
        }

        $this->runUntilCaughtUp($base->addHours(3)->addMinutes(5));

        $this->assertSame(180, CheckRollup::query()->where('bucket', 'minute')->count());
        $this->assertSame(180, (int) CheckRollup::query()->where('bucket', 'minute')->sum('total'));

        $third = $this->rollupRow($monitor, 'minute', $base->getTimestamp() + 2 * 60);
        $this->assertSame(['total' => 1, 'down' => 1, 'latency_avg' => null, 'latency_count' => 0], array_intersect_key($third, array_flip(['total', 'down', 'latency_avg', 'latency_count'])));

        $this->assertSame(3, CheckRollup::query()->where('bucket', 'hour')->count());
        foreach ($expected['hour'] as $hour => $items) {
            $latencies = array_values(array_filter(array_column($items, 1), is_int(...)));
            $row = $this->rollupRow($monitor, 'hour', $hour);
            $this->assertSame(60, $row['total']);
            foreach (['up', 'down', 'degraded', 'pending', 'maintenance'] as $status) {
                $this->assertSame(count(array_filter($items, fn ($item) => $item[0] === $status)), $row[$status], "hour {$status}");
            }
            $sorted = $latencies;
            sort($sorted);
            $this->assertSame($sorted[0] ?? null, $row['latency_min']);
            $this->assertSame($sorted[count($sorted) - 1] ?? null, $row['latency_max']);
            $this->assertSame(array_sum($latencies), $row['latency_sum']);
            $this->assertSame(count($latencies), $row['latency_count']);
            $this->assertSame((int) round(array_sum($latencies) / count($latencies)), $row['latency_avg']);
        }

        $day = $this->rollupRow($monitor, 'day', $base->startOfDay()->getTimestamp());
        foreach (['total', 'up', 'down', 'degraded', 'pending', 'maintenance'] as $field) {
            $this->assertSame($expected['day'][$field], $day[$field], "day {$field}");
        }
        $this->assertSame($expected['day']['sum'], $day['latency_sum']);
        $this->assertSame($expected['day']['count'], $day['latency_count']);
        // The day average is weighted by the number of latency samples, not an average of hourly averages.
        $this->assertSame((int) round($expected['day']['sum'] / $expected['day']['count']), $day['latency_avg']);
    }

    public function test_rollup_is_idempotent_and_absorbs_late_results(): void
    {
        $monitor = $this->monitor();
        $base = CarbonImmutable::parse('2026-03-10 10:00:00', 'UTC');
        for ($i = 0; $i < 10; $i++) {
            $this->insertResult($monitor, $base->addMinutes($i)->addSeconds(5), 'up', 100 + $i);
        }

        $now = $base->addMinutes(12);
        $this->builder()->run($now);
        $snapshot = CheckRollup::query()->orderBy('id')->get()->toArray();
        $this->builder()->run($now);
        $this->assertSame($snapshot, CheckRollup::query()->orderBy('id')->get()->toArray());

        // A result that arrives three minutes late is folded into its minute, hour and day by the next run.
        $this->insertResult($monitor, $base->addMinutes(8)->addSeconds(30), 'down', null);
        $this->builder()->run($now->addMinute());

        $minute = $this->rollupRow($monitor, 'minute', $base->addMinutes(8)->getTimestamp());
        $this->assertSame([2, 1, 1], [$minute['total'], $minute['up'], $minute['down']]);
        $this->assertSame(11, $this->rollupRow($monitor, 'hour', $base->getTimestamp())['total']);
        $this->assertSame(11, $this->rollupRow($monitor, 'day', $base->startOfDay()->getTimestamp())['total']);
        $this->assertSame(1, CheckRollup::query()->where('bucket', 'hour')->count());
    }

    public function test_day_bucket_uses_workspace_timezone(): void
    {
        $monitor = $this->monitor('Europe/Budapest');
        // Local midnight of 15 January in Budapest (CET, +01:00) is 23:00 UTC the day before.
        $this->insertResult($monitor, CarbonImmutable::parse('2026-01-14 23:30:00', 'UTC'));
        $this->insertResult($monitor, CarbonImmutable::parse('2026-01-15 00:30:00', 'UTC'));

        $this->runUntilCaughtUp(CarbonImmutable::parse('2026-01-15 01:00:00', 'UTC'), 3);

        $midnight = CarbonImmutable::parse('2026-01-14 23:00:00', 'UTC')->getTimestamp();
        $this->assertSame(2, $this->rollupRow($monitor, 'day', $midnight)['total']);
        $this->assertSame(1, CheckRollup::query()->where('bucket', 'day')->count());
        $this->assertSame(2, CheckRollup::query()->where('bucket', 'hour')->count());
    }

    public function test_day_bucket_handles_half_hour_offsets_exactly(): void
    {
        $monitor = $this->monitor('Asia/Kolkata');
        // Local midnight of 15 January in Kolkata (+05:30) is 18:30 UTC the day before; the 18:00 UTC hour
        // straddles two local days.
        $this->insertResult($monitor, CarbonImmutable::parse('2026-01-14 18:10:00', 'UTC'));
        $this->insertResult($monitor, CarbonImmutable::parse('2026-01-14 18:40:00', 'UTC'));
        $this->insertResult($monitor, CarbonImmutable::parse('2026-01-14 19:20:00', 'UTC'));

        $this->runUntilCaughtUp(CarbonImmutable::parse('2026-01-14 19:30:00', 'UTC'), 4);

        $this->assertSame(1, $this->rollupRow($monitor, 'day', CarbonImmutable::parse('2026-01-13 18:30:00', 'UTC')->getTimestamp())['total']);
        $this->assertSame(2, $this->rollupRow($monitor, 'day', CarbonImmutable::parse('2026-01-14 18:30:00', 'UTC')->getTimestamp())['total']);
        $this->assertSame(2, $this->rollupRow($monitor, 'hour', CarbonImmutable::parse('2026-01-14 18:00:00', 'UTC')->getTimestamp())['total']);
    }

    public function test_rebuild_days_moves_day_boundaries_to_the_new_timezone(): void
    {
        $monitor = $this->monitor();
        $this->insertResult($monitor, CarbonImmutable::parse('2026-01-14 18:10:00', 'UTC'));
        $this->insertResult($monitor, CarbonImmutable::parse('2026-01-14 18:40:00', 'UTC'));
        $this->insertResult($monitor, CarbonImmutable::parse('2026-01-14 19:20:00', 'UTC'));
        $now = CarbonImmutable::parse('2026-01-14 19:30:00', 'UTC');
        $this->runUntilCaughtUp($now, 4);
        $this->assertSame(3, $this->rollupRow($monitor, 'day', CarbonImmutable::parse('2026-01-14', 'UTC')->getTimestamp())['total']);

        CarbonImmutable::setTestNow($now);
        $workspace = $this->workspaceOf($monitor);
        $workspace->update(['timezone' => 'Asia/Kolkata']);
        $this->builder()->rebuildDays($workspace);

        $days = CheckRollup::query()->where('bucket', 'day')->orderBy('bucket_start')->pluck('total', 'bucket_start')->all();
        $this->assertSame([
            CarbonImmutable::parse('2026-01-13 18:30:00', 'UTC')->getTimestamp() => 1,
            CarbonImmutable::parse('2026-01-14 18:30:00', 'UTC')->getTimestamp() => 2,
        ], $days);
    }

    public function test_rebuild_days_uses_hour_rows_beyond_minute_retention(): void
    {
        $monitor = $this->monitor('Europe/Budapest');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));

        // Ten days ago: only hour rows are retained. Budapest midnight of 28 February is 23:00 UTC on the 27th.
        $hours = [
            '2026-02-27 22:00:00' => 5, // previous local day
            '2026-02-27 23:00:00' => 7,
            '2026-02-28 12:00:00' => 11,
            '2026-02-28 22:00:00' => 13,
            '2026-02-28 23:00:00' => 17, // next local day
        ];
        foreach ($hours as $start => $total) {
            DB::table('check_rollups')->insert([
                'monitor_id' => $monitor->id, 'bucket' => 'hour',
                'bucket_start' => CarbonImmutable::parse($start, 'UTC')->getTimestamp(),
                'total' => $total, 'up' => $total, 'down' => 0, 'degraded' => 0, 'pending' => 0, 'maintenance' => 0,
                'latency_min' => 10, 'latency_max' => 50, 'latency_avg' => 20, 'latency_sum' => 20 * $total, 'latency_count' => $total,
            ]);
        }

        $this->builder()->rebuildDays($this->workspaceOf($monitor));

        $day = $this->rollupRow($monitor, 'day', CarbonImmutable::parse('2026-02-27 23:00:00', 'UTC')->getTimestamp());
        $this->assertSame(7 + 11 + 13, $day['total']);
        $this->assertSame(20, $day['latency_avg']);
        $this->assertSame(31, $day['latency_count']);
    }

    public function test_missing_watermark_restarts_from_the_newest_minute_rollup(): void
    {
        $monitor = $this->monitor();
        $base = CarbonImmutable::parse('2026-03-10 10:00:00', 'UTC');
        for ($i = 0; $i <= 40; $i++) {
            $this->insertResult($monitor, $base->addMinutes($i));
        }
        DB::table('check_rollups')->insert([
            'monitor_id' => $monitor->id, 'bucket' => 'minute', 'bucket_start' => $base->addMinutes(20)->getTimestamp(),
            'total' => 1, 'up' => 1, 'down' => 0, 'degraded' => 0, 'pending' => 0, 'maintenance' => 0,
            'latency_min' => 100, 'latency_max' => 100, 'latency_avg' => 100, 'latency_sum' => 100, 'latency_count' => 1,
        ]);
        Cache::forget(RollupBuilder::WATERMARK_KEY);

        $this->builder()->run($base->addMinutes(42));

        $minutes = CheckRollup::query()->where('bucket', 'minute')->orderBy('bucket_start')->pluck('bucket_start')->all();
        $this->assertSame($base->addMinutes(20)->getTimestamp(), $minutes[0]);
        $this->assertCount(21, $minutes);
        $this->assertSame($base->addMinutes(41)->getTimestamp(), Cache::get(RollupBuilder::WATERMARK_KEY));
    }

    public function test_missing_watermark_without_rollups_starts_at_the_oldest_raw_row_and_catches_up_in_steps(): void
    {
        $monitor = $this->monitor();
        $base = CarbonImmutable::parse('2026-03-10 08:00:00', 'UTC');
        for ($i = 0; $i <= 120; $i++) {
            $this->insertResult($monitor, $base->addMinutes($i));
        }
        $now = $base->addMinutes(125);

        $this->builder()->run($now);
        $this->assertSame(60, CheckRollup::query()->where('bucket', 'minute')->count());
        $this->assertSame($base->addHour()->getTimestamp(), Cache::get(RollupBuilder::WATERMARK_KEY));

        $this->builder()->run($now);
        $this->assertSame(115, CheckRollup::query()->where('bucket', 'minute')->count());

        $this->builder()->run($now);
        $this->assertSame(121, CheckRollup::query()->where('bucket', 'minute')->count());
        $this->assertSame($now->subMinute()->getTimestamp(), Cache::get(RollupBuilder::WATERMARK_KEY));
    }

    public function test_availability_excludes_maintenance_and_counts_pending(): void
    {
        $rollup = new CheckRollup(['total' => 10, 'up' => 4, 'degraded' => 1, 'pending' => 1, 'down' => 2, 'maintenance' => 2]);
        $this->assertSame(0.75, $rollup->availability());

        $maintenanceOnly = new CheckRollup(['total' => 3, 'up' => 0, 'degraded' => 0, 'pending' => 0, 'down' => 0, 'maintenance' => 3]);
        $this->assertNull($maintenanceOnly->availability());

        $monitor = $this->monitor();
        $workspace = $this->workspaceOf($monitor);
        $user = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();
        DB::table('check_rollups')->insert([
            'monitor_id' => $monitor->id, 'bucket' => 'hour', 'bucket_start' => 1_773_136_800,
            'total' => 10, 'up' => 4, 'down' => 2, 'degraded' => 1, 'pending' => 1, 'maintenance' => 2,
            'latency_min' => null, 'latency_max' => null, 'latency_avg' => null, 'latency_sum' => 0, 'latency_count' => 0,
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/workspaces/{$workspace->slug}/monitors/{$monitor->id}/rollups?bucket=hour")
            ->assertOk()
            ->assertJsonPath('data.0.availability', 0.75)
            ->assertJsonPath('data.0.pending', 1);
    }

    public function test_timezone_change_dispatches_a_day_rebuild_only_when_it_changes(): void
    {
        Queue::fake();
        $workspace = Workspace::factory()->create(['timezone' => 'UTC']);
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Owner)->create();
        $url = "/api/v1/workspaces/{$workspace->slug}";

        $this->actingAs($owner)->patchJson($url, ['name' => 'Renamed'])->assertOk();
        $this->actingAs($owner)->patchJson($url, ['name' => 'Renamed', 'timezone' => 'UTC'])->assertOk();
        Queue::assertNotPushed(RebuildDayRollups::class);

        $this->actingAs($owner)->patchJson($url, ['name' => 'Renamed', 'timezone' => 'Asia/Kolkata'])->assertOk();
        Queue::assertPushed(RebuildDayRollups::class, 1);
        Queue::assertPushed(RebuildDayRollups::class, fn (RebuildDayRollups $job) => $job->workspaceId === $workspace->id);
    }

    public function test_rebuild_job_rebuilds_days(): void
    {
        $monitor = $this->monitor('Asia/Kolkata');
        $this->insertResult($monitor, CarbonImmutable::parse('2026-01-14 18:40:00', 'UTC'));
        $now = CarbonImmutable::parse('2026-01-14 19:00:00', 'UTC');
        $this->runUntilCaughtUp($now, 2);
        CheckRollup::query()->where('bucket', 'day')->delete();
        CarbonImmutable::setTestNow($now);

        (new RebuildDayRollups($monitor->workspace_id))->handle($this->builder());

        $this->assertSame(1, $this->rollupRow($monitor, 'day', CarbonImmutable::parse('2026-01-14 18:30:00', 'UTC')->getTimestamp())['total']);
    }

    public function test_rollup_and_prune_are_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events());
        $find = fn (string $command): Event => $events->first(fn (Event $e) => str_contains((string) $e->command, $command))
            ?? $this->fail("{$command} is not scheduled");

        $rollup = $find('intrawatch:rollup');
        $this->assertSame('* * * * *', $rollup->expression);
        $this->assertTrue($rollup->withoutOverlapping);
        $this->assertTrue($rollup->onOneServer);

        $prune = $find('intrawatch:prune');
        $this->assertSame('30 * * * *', $prune->expression);
        $this->assertTrue($prune->onOneServer);

        $this->assertSame('0 0 * * 0', $find('intrawatch:rdap:refresh')->expression);
    }

    public function test_rollup_command_runs(): void
    {
        $monitor = $this->monitor();
        $this->insertResult($monitor, CarbonImmutable::now()->subMinutes(3));

        $this->assertSame(0, Artisan::call('intrawatch:rollup'));

        $this->assertSame(1, CheckRollup::query()->where('bucket', 'minute')->count());
    }
}
