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
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
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
        // The lookback applies here too, so late rows just before the newest minute rollup are not lost.
        $this->assertSame($base->addMinutes(15)->getTimestamp(), $minutes[0]);
        $this->assertCount(26, $minutes);
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

    public function test_a_result_twenty_minutes_late_is_counted_at_every_level(): void
    {
        $monitor = $this->monitor();
        $base = CarbonImmutable::parse('2026-03-10 10:00:00', 'UTC');
        for ($i = 0; $i <= 30; $i++) {
            $this->insertResult($monitor, $base->addMinutes($i)->addSeconds(5));
        }
        $this->builder()->run($base->addMinutes(32));
        $this->builder()->run($base->addMinutes(33));

        // Flushed from a worker buffer after an outage: far outside the five-minute lookback.
        $this->insertResult($monitor, $base->addMinutes(10)->addSeconds(30), 'down', null);
        $this->builder()->run($base->addMinutes(34));

        $minute = $this->rollupRow($monitor, 'minute', $base->addMinutes(10)->getTimestamp());
        $this->assertSame([2, 1], [$minute['total'], $minute['down']]);
        $this->assertSame(32, $this->rollupRow($monitor, 'hour', $base->getTimestamp())['total']);
        $this->assertSame(32, $this->rollupRow($monitor, 'day', $base->startOfDay()->getTimestamp())['total']);
    }

    public function test_a_missing_result_id_watermark_starts_at_the_newest_row_instead_of_reprocessing_the_table(): void
    {
        $monitor = $this->monitor();
        $base = CarbonImmutable::parse('2026-03-10 10:00:00', 'UTC');
        for ($i = 0; $i <= 30; $i++) {
            $this->insertResult($monitor, $base->addMinutes($i));
        }
        $this->builder()->run($base->addMinutes(32));

        // Marker: an old minute row that a full reprocessing would overwrite.
        DB::table('check_rollups')->where(['bucket' => 'minute', 'bucket_start' => $base->addMinutes(5)->getTimestamp()])->update(['total' => 99]);
        Cache::forget(RollupBuilder::LAST_ID_KEY);

        $this->builder()->run($base->addMinutes(33));

        $this->assertSame(99, $this->rollupRow($monitor, 'minute', $base->addMinutes(5)->getTimestamp())['total']);
        $this->assertEquals(DB::table('check_results')->max('id'), Cache::get(RollupBuilder::LAST_ID_KEY));
    }

    public function test_late_results_are_worked_off_in_bounded_id_batches(): void
    {
        config(['monitoring.rollups.late_id_batch' => 1]);
        $monitor = $this->monitor();
        $base = CarbonImmutable::parse('2026-03-10 10:00:00', 'UTC');
        for ($i = 0; $i <= 30; $i++) {
            $this->insertResult($monitor, $base->addMinutes($i));
        }
        $now = $base->addMinutes(32);
        $this->builder()->run($now);

        $this->insertResult($monitor, $base->addMinutes(3)->addSeconds(30));
        $this->insertResult($monitor, $base->addMinutes(4)->addSeconds(30));

        $this->builder()->run($now);
        $this->assertSame(2, $this->rollupRow($monitor, 'minute', $base->addMinutes(3)->getTimestamp())['total']);
        $this->assertSame(1, $this->rollupRow($monitor, 'minute', $base->addMinutes(4)->getTimestamp())['total']);

        $this->builder()->run($now);
        $this->assertSame(2, $this->rollupRow($monitor, 'minute', $base->addMinutes(4)->getTimestamp())['total']);
    }

    public function test_unchanged_rollups_are_not_rewritten(): void
    {
        $monitor = $this->monitor();
        $base = CarbonImmutable::parse('2026-03-10 10:00:00', 'UTC');
        for ($i = 0; $i < 10; $i++) {
            $this->insertResult($monitor, $base->addMinutes($i));
        }
        $now = $base->addMinutes(11);
        $this->builder()->run($now);

        $writes = 0;
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^(insert into|update) ["`]check_rollups["`]/i', $query->sql) === 1) {
                $writes++;
            }
        });
        $this->builder()->run($now);

        $this->assertSame(0, $writes);
    }

    public function test_results_of_a_deleted_monitor_produce_no_rollups(): void
    {
        $monitor = $this->monitor();
        $base = CarbonImmutable::parse('2026-03-10 10:00:00', 'UTC');
        $this->insertResult($monitor, $base);
        // Deleted, but the purge job has not removed the raw rows yet.
        DB::table('monitor_states')->where('monitor_id', $monitor->id)->delete();
        DB::table('monitors')->where('id', $monitor->id)->delete();

        $this->builder()->run($base->addMinutes(3));

        $this->assertSame(0, CheckRollup::query()->count());
    }

    public function test_rollup_run_is_skipped_while_a_day_rebuild_holds_the_lock(): void
    {
        $monitor = $this->monitor();
        $base = CarbonImmutable::parse('2026-03-10 10:00:00', 'UTC');
        $this->insertResult($monitor, $base);

        $lock = Cache::lock(RollupBuilder::LOCK_KEY, 60);
        $this->assertTrue($lock->get());
        $this->builder()->run($base->addMinutes(3));
        $this->assertSame(0, CheckRollup::query()->count());
        $this->assertNull(Cache::get(RollupBuilder::WATERMARK_KEY));

        $lock->release();
        $this->builder()->run($base->addMinutes(3));
        $this->assertSame(1, CheckRollup::query()->where('bucket', 'minute')->count());
    }

    public function test_day_buckets_follow_dst_in_europe_budapest(): void
    {
        $monitor = $this->monitor('Europe/Budapest');
        // 29 March 2026 has 23 hours: 2026-03-28T23:00Z to 2026-03-29T22:00Z.
        $this->insertResult($monitor, CarbonImmutable::parse('2026-03-28 23:05:00', 'UTC'));
        $this->insertResult($monitor, CarbonImmutable::parse('2026-03-29 21:55:00', 'UTC'));
        $this->insertResult($monitor, CarbonImmutable::parse('2026-03-29 22:05:00', 'UTC'));
        $this->runUntilCaughtUp(CarbonImmutable::parse('2026-03-29 22:30:00', 'UTC'), 40);

        $this->assertSame(2, $this->rollupRow($monitor, 'day', CarbonImmutable::parse('2026-03-28 23:00:00', 'UTC')->getTimestamp())['total']);
        $this->assertSame(1, $this->rollupRow($monitor, 'day', CarbonImmutable::parse('2026-03-29 22:00:00', 'UTC')->getTimestamp())['total']);

        // 25 October 2026 has 25 hours: 2026-10-24T22:00Z to 2026-10-25T23:00Z.
        $autumn = $this->monitor('Europe/Budapest');
        Cache::flush();
        $this->insertResult($autumn, CarbonImmutable::parse('2026-10-24 22:05:00', 'UTC'));
        $this->insertResult($autumn, CarbonImmutable::parse('2026-10-25 22:55:00', 'UTC'));
        $this->insertResult($autumn, CarbonImmutable::parse('2026-10-25 23:05:00', 'UTC'));
        DB::table('check_results')->where('monitor_id', $monitor->id)->delete();
        $this->runUntilCaughtUp(CarbonImmutable::parse('2026-10-25 23:30:00', 'UTC'), 40);

        $this->assertSame(2, $this->rollupRow($autumn, 'day', CarbonImmutable::parse('2026-10-24 22:00:00', 'UTC')->getTimestamp())['total']);
        $this->assertSame(1, $this->rollupRow($autumn, 'day', CarbonImmutable::parse('2026-10-25 23:00:00', 'UTC')->getTimestamp())['total']);
    }

    public function test_rebuild_job_is_unique_per_workspace_and_bounded(): void
    {
        $job = new RebuildDayRollups(42);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('42', (string) $job->uniqueId());
        $this->assertSame(900, $job->timeout);
        $this->assertSame(1, $job->tries);

        Queue::fake();
        RebuildDayRollups::dispatch(42);
        RebuildDayRollups::dispatch(42);
        RebuildDayRollups::dispatch(43);
        Queue::assertPushed(RebuildDayRollups::class, 2);
    }

    /**
     * Five days of hour rows at 12:00 UTC, beyond the minute retention, with their UTC day rows.
     */
    private function seedOldDays(Monitor $monitor): void
    {
        for ($day = 1; $day <= 5; $day++) {
            foreach (['hour' => "2026-03-0{$day} 12:00:00", 'day' => "2026-03-0{$day} 00:00:00"] as $bucket => $start) {
                DB::table('check_rollups')->insert([
                    'monitor_id' => $monitor->id, 'bucket' => $bucket,
                    'bucket_start' => CarbonImmutable::parse($start, 'UTC')->getTimestamp(),
                    'total' => 1, 'up' => 1, 'down' => 0, 'degraded' => 0, 'pending' => 0, 'maintenance' => 0,
                    'latency_min' => 10, 'latency_max' => 10, 'latency_avg' => 10, 'latency_sum' => 10, 'latency_count' => 1,
                ]);
            }
        }
    }

    private function assertNoOverlappingDays(Monitor $monitor): void
    {
        $starts = CheckRollup::query()->where(['monitor_id' => $monitor->id, 'bucket' => 'day'])->orderBy('bucket_start')->get()
            ->map(fn (CheckRollup $row): int => $row->bucket_start)->all();
        for ($i = 1; $i < count($starts); $i++) {
            $this->assertGreaterThanOrEqual(23 * 3600, $starts[$i] - $starts[$i - 1], 'Overlapping day rows: '.gmdate('c', $starts[$i - 1]).' and '.gmdate('c', $starts[$i]));
        }
        $this->assertLessThanOrEqual(5, (int) CheckRollup::query()->where(['monitor_id' => $monitor->id, 'bucket' => 'day'])->sum('total'));
    }

    public function test_an_interrupted_rebuild_never_leaves_overlapping_day_rows(): void
    {
        $monitor = $this->monitor();
        $this->seedOldDays($monitor);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));
        $workspace = $this->workspaceOf($monitor);
        $workspace->update(['timezone' => 'Asia/Kolkata']);

        $dayWrites = 0;
        DB::listen(function (QueryExecuted $query) use (&$dayWrites): void {
            if (preg_match('/^delete from ["`]check_rollups["`]/i', $query->sql) === 1 && in_array('day', $query->bindings, true) && ++$dayWrites === 3) {
                throw new RuntimeException('Simulated worker kill');
            }
        });

        try {
            $this->builder()->rebuildDays($workspace);
            $this->fail('The simulated failure did not happen');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated worker kill', $e->getMessage());
        }
        $this->assertNoOverlappingDays($monitor);

        $dayWrites = -1000;
        $this->builder()->rebuildDays($workspace);
        $this->assertNoOverlappingDays($monitor);
        $this->assertSame(5, (int) CheckRollup::query()->where(['monitor_id' => $monitor->id, 'bucket' => 'day'])->sum('total'));
        $this->assertSame(5, CheckRollup::query()->where(['monitor_id' => $monitor->id, 'bucket' => 'day'])->count());
        $this->assertSame(
            CarbonImmutable::parse('2026-03-01 12:00:00', 'UTC')->setTimezone('Asia/Kolkata')->startOfDay()->getTimestamp(),
            CheckRollup::query()->where(['monitor_id' => $monitor->id, 'bucket' => 'day'])->min('bucket_start'),
        );
    }

    public function test_rebuild_reruns_when_the_timezone_changes_meanwhile(): void
    {
        $monitor = $this->monitor();
        $this->seedOldDays($monitor);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));
        $workspace = $this->workspaceOf($monitor);
        $workspace->update(['timezone' => 'Asia/Kolkata']);

        $changed = false;
        DB::listen(function (QueryExecuted $query) use (&$changed, $workspace): void {
            if (! $changed && preg_match('/^delete from ["`]check_rollups["`]/i', $query->sql) === 1) {
                $changed = true;
                DB::table('workspaces')->where('id', $workspace->id)->update(['timezone' => 'America/New_York']);
            }
        });

        (new RebuildDayRollups($workspace->id))->handle($this->builder());

        $expected = CarbonImmutable::parse('2026-03-01 12:00:00', 'UTC')->setTimezone('America/New_York')->startOfDay()->getTimestamp();
        $this->assertSame($expected, CheckRollup::query()->where(['monitor_id' => $monitor->id, 'bucket' => 'day'])->min('bucket_start'));
        $this->assertNoOverlappingDays($monitor);
    }

    public function test_retention_config_is_clamped_to_safe_minimums(): void
    {
        putenv('RETENTION_MINUTE_HOURS=2');
        putenv('RETENTION_RAW_HOURS=0');
        try {
            $config = require config_path('monitoring.php');
        } finally {
            putenv('RETENTION_MINUTE_HOURS');
            putenv('RETENTION_RAW_HOURS');
        }

        $this->assertIsArray($config);
        $this->assertSame(['raw_hours' => 1, 'minute_hours' => 25], array_intersect_key(
            is_array($config['retention'] ?? null) ? $config['retention'] : [],
            ['raw_hours' => true, 'minute_hours' => true],
        ));
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
        $this->assertTrue($prune->withoutOverlapping);
        $this->assertSame(180, $prune->expiresAt);

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
