<?php

declare(strict_types=1);

namespace Tests\Feature\Import\Kuma;

use App\Import\Kuma\ImportOptions;
use App\Import\Kuma\ImportReport;
use App\Import\Kuma\KumaImporter;
use App\Import\Kuma\Source\KumaSource;
use App\Import\Kuma\Source\SourceFactory;
use App\Models\CheckRollup;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Workspace;
use App\Monitoring\Engine\RollupBuilder;
use App\Status\Pages\ItemView;
use App\Status\Pages\StatusPageSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * @phpstan-import-type ReportShape from ImportReport
 */
final class ImportHistoryTest extends TestCase
{
    use RefreshDatabase;

    /** 2026-11-07 22:00:00 UTC, the single hour bucket of the fixtures. */
    private const FIXTURE_HOUR = 1794088800;

    /** 2026-11-07 00:00:00 UTC, the single day bucket of the fixtures. */
    private const FIXTURE_DAY = 1794009600;

    /** @var list<KumaSource> */
    private array $sources = [];

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-08 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        foreach ($this->sources as $source) {
            $source->close();
        }
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function workspace(?string $timezone = null): Workspace
    {
        return Workspace::factory()->create($timezone === null ? [] : ['timezone' => $timezone])->refresh();
    }

    /**
     * @return ReportShape
     */
    private function import(string $path, Workspace $workspace, bool $dryRun = false, bool $history = true): array
    {
        $source = $this->sources[] = SourceFactory::open($path);

        return app(KumaImporter::class)
            ->run($source, $workspace, new ImportOptions(dryRun: $dryRun, history: $history), null)
            ->toArray();
    }

    /**
     * A copy of a fixture database with the given statements applied.
     *
     * @param  list<string>  $statements
     */
    private function patched(string $name, array $statements): string
    {
        $path = tempnam(sys_get_temp_dir(), 'iw-kuma-');
        $this->assertNotFalse($path);
        $this->files[] = $path;
        copy(ImporterTest::fixture($name), $path);
        $pdo = new PDO('sqlite:'.$path);
        $pdo->beginTransaction();
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        $pdo->commit();

        return $path;
    }

    /**
     * @param  ReportShape  $report
     */
    private function monitorId(array $report, int $sourceId): int
    {
        foreach ($report['entries'] as $entry) {
            if ($entry['entity'] === 'monitor' && $entry['source_id'] === $sourceId && $entry['target_id'] !== null) {
                return $entry['target_id'];
            }
        }
        $this->fail("Source monitor {$sourceId} was not imported.");
    }

    private function rollup(int $monitorId, string $bucket, int $start): ?CheckRollup
    {
        return CheckRollup::query()
            ->where('monitor_id', $monitorId)
            ->where('bucket', $bucket)
            ->where('bucket_start', $start)
            ->first();
    }

    /**
     * @param  array<string, int|null>  $expected
     */
    private function assertRollup(?CheckRollup $row, array $expected): void
    {
        $this->assertNotNull($row);
        foreach ($expected as $column => $value) {
            $this->assertSame($value, $row->getAttribute($column), $column);
        }
    }

    /**
     * @param  ReportShape  $report
     * @return list<string>
     */
    private static function historyCodes(array $report): array
    {
        return array_map(fn (array $n): string => $n['code'], $report['history']['notes']);
    }

    public function test_kuma2_stats_become_hour_and_day_rollups(): void
    {
        $report = $this->import(ImporterTest::fixture('kuma2.db'), $this->workspace());

        $this->assertSame(8, $report['history']['monitors']);
        $this->assertSame(8, $report['history']['hour_rows']);
        $this->assertSame(8, $report['history']['day_rows']);
        $this->assertSame(8, CheckRollup::query()->where('bucket', 'hour')->count());
        $this->assertSame(8, CheckRollup::query()->where('bucket', 'day')->count());
        $this->assertSame(0, CheckRollup::query()->where('bucket', 'minute')->count());

        $http = $this->monitorId($report, 2);
        $expected = [
            'total' => 5, 'up' => 5, 'down' => 0, 'degraded' => 0, 'pending' => 0, 'maintenance' => 0,
            'latency_min' => 67, 'latency_max' => 105, 'latency_avg' => 83, 'latency_sum' => 417, 'latency_count' => 5,
        ];
        $this->assertRollup($this->rollup($http, 'hour', self::FIXTURE_HOUR), $expected);
        $this->assertRollup($this->rollup($http, 'day', self::FIXTURE_DAY), $expected);

        $group = $this->monitorId($report, 1);
        $this->assertRollup($this->rollup($group, 'hour', self::FIXTURE_HOUR), [
            'total' => 5, 'up' => 4, 'down' => 1, 'latency_min' => 8, 'latency_max' => 12, 'latency_avg' => 11, 'latency_sum' => 43, 'latency_count' => 4,
        ]);

        $push = $this->monitorId($report, 9);
        $this->assertRollup($this->rollup($push, 'hour', self::FIXTURE_HOUR), [
            'total' => 5, 'up' => 1, 'down' => 4, 'latency_sum' => 12, 'latency_count' => 1,
        ]);

        $ping = $this->monitorId($report, 7);
        $this->assertRollup($this->rollup($ping, 'hour', self::FIXTURE_HOUR), [
            'latency_min' => 0, 'latency_max' => 0, 'latency_avg' => 0, 'latency_sum' => 0, 'latency_count' => 5,
        ]);

        $this->assertContains('history_pending_as_down', self::historyCodes($report));
        $this->assertNotContains('history_days_local_midnight', self::historyCodes($report));
    }

    public function test_daily_rows_use_workspace_local_midnight_with_note(): void
    {
        $report = $this->import(ImporterTest::fixture('kuma2.db'), $this->workspace('Europe/Budapest'));

        $localMidnight = CarbonImmutable::parse('2026-11-07 00:00:00', 'Europe/Budapest')->getTimestamp();
        $this->assertSame(self::FIXTURE_DAY - 3600, $localMidnight);

        $http = $this->monitorId($report, 2);
        $this->assertNull($this->rollup($http, 'day', self::FIXTURE_DAY));
        $this->assertRollup($this->rollup($http, 'day', $localMidnight), ['total' => 5, 'up' => 5]);
        $this->assertContains('history_days_local_midnight', self::historyCodes($report));
    }

    public function test_days_with_hours_are_rebuilt_from_hours(): void
    {
        $statements = ['DELETE FROM stat_hourly', 'DELETE FROM stat_daily'];
        $firstHour = CarbonImmutable::parse('2026-11-05 00:00:00 UTC')->getTimestamp();
        for ($i = 0; $i < 72; $i++) {
            $statements[] = 'INSERT INTO stat_hourly (monitor_id, timestamp, up, down, ping, ping_min, ping_max, extras) VALUES (2, '
                .($firstHour + $i * 3600).", 4, 1, 100, 90, 120, '')";
        }
        foreach (['2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07'] as $date) {
            $statements[] = 'INSERT INTO stat_daily (monitor_id, timestamp, up, down, ping, ping_min, ping_max, extras) VALUES (2, '
                .CarbonImmutable::parse($date.' UTC')->getTimestamp().", 1000, 0, 50, 40, 60, '')";
        }

        $report = $this->import($this->patched('kuma2.db', $statements), $this->workspace());
        $http = $this->monitorId($report, 2);

        $this->assertSame(72, $report['history']['hour_rows']);
        $before = $this->rollup($http, 'day', CarbonImmutable::parse('2026-11-04 UTC')->getTimestamp());
        $this->assertRollup($before, ['up' => 1000, 'total' => 1000, 'latency_sum' => 50000, 'latency_count' => 1000]);

        foreach (['2026-11-05', '2026-11-06', '2026-11-07'] as $date) {
            $this->assertRollup($this->rollup($http, 'day', CarbonImmutable::parse($date.' UTC')->getTimestamp()), [
                'total' => 120, 'up' => 96, 'down' => 24, 'latency_min' => 90, 'latency_max' => 120,
                'latency_avg' => 100, 'latency_sum' => 9600, 'latency_count' => 96,
            ]);
        }
        $this->assertSame(4, CheckRollup::query()->where('bucket', 'day')->where('monitor_id', $http)->count());
        $this->assertSame(4, $report['history']['day_rows']);
    }

    public function test_kuma1_heartbeats_aggregate_with_pending(): void
    {
        $report = $this->import(ImporterTest::fixture('kuma1.db'), $this->workspace());

        $group = $this->monitorId($report, 1);
        $expected = [
            'total' => 5, 'up' => 4, 'down' => 0, 'degraded' => 0, 'pending' => 1, 'maintenance' => 0,
            'latency_min' => null, 'latency_max' => null, 'latency_avg' => null, 'latency_sum' => 0, 'latency_count' => 0,
        ];
        $this->assertRollup($this->rollup($group, 'hour', self::FIXTURE_HOUR), $expected);
        $this->assertRollup($this->rollup($group, 'day', self::FIXTURE_DAY), $expected);

        $http = $this->monitorId($report, 2);
        $this->assertRollup($this->rollup($http, 'hour', self::FIXTURE_HOUR), [
            'total' => 5, 'up' => 5, 'pending' => 0, 'latency_min' => 64, 'latency_max' => 101,
            'latency_avg' => 84, 'latency_sum' => 422, 'latency_count' => 5,
        ]);
        $this->assertRollup($this->rollup($http, 'day', self::FIXTURE_DAY), ['total' => 5, 'latency_sum' => 422]);

        $this->assertSame(7, $report['history']['monitors']);
        $this->assertSame(7, $report['history']['hour_rows']);
        $this->assertSame(7, $report['history']['day_rows']);
        $this->assertNotContains('history_pending_as_down', self::historyCodes($report));
    }

    public function test_heartbeat_days_use_workspace_local_days(): void
    {
        $report = $this->import(ImporterTest::fixture('kuma1.db'), $this->workspace('Europe/Budapest'));

        $localMidnight = CarbonImmutable::parse('2026-11-07 00:00:00', 'Europe/Budapest')->getTimestamp();
        $http = $this->monitorId($report, 2);
        $this->assertRollup($this->rollup($http, 'day', $localMidnight), ['total' => 5, 'up' => 5]);
        $this->assertRollup($this->rollup($http, 'hour', self::FIXTURE_HOUR), ['total' => 5]);
    }

    public function test_history_inserts_are_chunked(): void
    {
        $statements = [];
        for ($i = 1; $i <= 2000; $i++) {
            $statements[] = 'INSERT INTO stat_hourly (monitor_id, timestamp, up, down, ping, ping_min, ping_max, extras) VALUES (2, '
                .(self::FIXTURE_HOUR - $i * 3600).", 3, 1, 10, 9, 12, '')";
        }
        $path = $this->patched('kuma2.db', $statements);

        $statementRows = [];
        DB::listen(function ($query) use (&$statementRows): void {
            if (str_starts_with($query->sql, 'insert into "check_rollups"') || str_starts_with($query->sql, 'insert into `check_rollups`')) {
                $statementRows[] = intdiv(count($query->bindings), 14);
            }
        });

        $report = $this->import($path, $this->workspace());
        $http = $this->monitorId($report, 2);

        $this->assertSame(2008, $report['history']['hour_rows']);
        $this->assertSame(2001, CheckRollup::query()->where('bucket', 'hour')->where('monitor_id', $http)->count());
        $this->assertSame(7, CheckRollup::query()->where('bucket', 'hour')->where('monitor_id', '!=', $http)->count());
        $this->assertNotEmpty($statementRows);
        foreach ($statementRows as $rows) {
            $this->assertLessThanOrEqual(500, $rows);
        }
        $this->assertGreaterThanOrEqual(4, count(array_filter($statementRows, fn (int $n): bool => $n === 500)));
        $this->assertSame(2000 * 4 + 5, (int) CheckRollup::query()->where('bucket', 'hour')->where('monitor_id', $http)->sum('total'));
    }

    public function test_rows_in_the_same_bucket_collapse_and_are_counted_once(): void
    {
        $path = $this->patched('kuma2.db', [
            'INSERT INTO stat_hourly (monitor_id, timestamp, up, down, ping, ping_min, ping_max, extras) VALUES (2, '
                .(self::FIXTURE_HOUR + 1800).", 9, 0, 50, 40, 60, '')",
        ]);

        $report = $this->import($path, $this->workspace());
        $http = $this->monitorId($report, 2);

        $this->assertSame(8, $report['history']['hour_rows']);
        $this->assertSame(8, CheckRollup::query()->where('bucket', 'hour')->count());
        $this->assertRollup($this->rollup($http, 'hour', self::FIXTURE_HOUR), ['up' => 9]);
    }

    public function test_history_skipped_for_existing_monitors_and_when_disabled(): void
    {
        $workspace = $this->workspace();
        $path = ImporterTest::fixture('kuma2.db');

        $first = $this->import($path, $workspace);
        $this->assertSame(8, $first['history']['monitors']);
        CheckRollup::query()->delete();

        $second = $this->import($path, $workspace);
        $this->assertSame(0, $second['history']['monitors']);
        $this->assertSame(0, $second['history']['hour_rows']);
        $this->assertSame(0, CheckRollup::query()->count());

        $disabled = $this->import($path, $this->workspace(), history: false);
        $this->assertSame(0, $disabled['history']['monitors']);
        $this->assertContains('history_disabled', self::historyCodes($disabled));
        $this->assertSame(0, CheckRollup::query()->count());
    }

    public function test_dry_run_counts_rows_and_writes_none(): void
    {
        $report = $this->import(ImporterTest::fixture('kuma2.db'), $this->workspace(), dryRun: true);

        $this->assertSame(8, $report['history']['monitors']);
        $this->assertSame(8, $report['history']['hour_rows']);
        $this->assertSame(8, $report['history']['day_rows']);
        $this->assertSame(0, CheckRollup::query()->count());

        $heartbeats = $this->import(ImporterTest::fixture('kuma1.db'), $this->workspace(), dryRun: true);
        $this->assertSame(7, $heartbeats['history']['hour_rows']);
        $this->assertSame(0, CheckRollup::query()->count());
    }

    public function test_source_without_history_gets_a_note(): void
    {
        $report = $this->import(ImporterTest::fixture('kuma1-backup.json'), $this->workspace());

        $this->assertSame(0, $report['history']['monitors']);
        $this->assertContains('history_unavailable', self::historyCodes($report));
    }

    public function test_rows_older_than_retention_are_not_written(): void
    {
        $old = self::FIXTURE_HOUR - 100 * 86400;
        $oldDay = self::FIXTURE_DAY - 800 * 86400;
        $path = $this->patched('kuma2.db', [
            "INSERT INTO stat_hourly (monitor_id, timestamp, up, down, ping, ping_min, ping_max, extras) VALUES (2, {$old}, 1, 0, 5, 5, 5, '')",
            "INSERT INTO stat_daily (monitor_id, timestamp, up, down, ping, ping_min, ping_max, extras) VALUES (2, {$oldDay}, 1, 0, 5, 5, 5, '')",
        ]);

        $report = $this->import($path, $this->workspace());
        $http = $this->monitorId($report, 2);

        $this->assertNull($this->rollup($http, 'hour', $old));
        $this->assertNull($this->rollup($http, 'day', $oldDay));
        $this->assertSame(8, $report['history']['hour_rows']);
        $this->assertSame(8, $report['history']['day_rows']);
    }

    public function test_status_page_shows_imported_uptime(): void
    {
        $workspace = $this->workspace();
        $this->import(ImporterTest::fixture('kuma2.db'), $workspace);

        $page = StatusPage::query()->where('workspace_id', $workspace->id)->firstOrFail();
        $view = (new StatusPageSnapshot)->build($page, CarbonImmutable::now());

        $known = 0;
        foreach ($view->sections as $section) {
            foreach ($section->items as $item) {
                foreach ([$item, ...$item->children] as $each) {
                    $known += $this->knownBars($each);
                }
            }
        }
        $this->assertGreaterThan(0, $known);
    }

    private function knownBars(ItemView $item): int
    {
        return count(array_filter($item->bars, fn ($bar): bool => $bar->date === '2026-11-07' && $bar->uptimePercent !== null));
    }

    public function test_rebuild_days_for_touches_only_the_given_monitors(): void
    {
        $workspace = $this->workspace();
        $a = Monitor::factory()->create(['workspace_id' => $workspace->id]);
        $b = Monitor::factory()->create(['workspace_id' => $workspace->id]);
        $start = CarbonImmutable::parse('2026-11-05 00:00:00 UTC')->getTimestamp();
        foreach ([$a, $b] as $monitor) {
            for ($i = 0; $i < 48; $i++) {
                CheckRollup::query()->create([
                    'monitor_id' => $monitor->id, 'bucket' => 'hour', 'bucket_start' => $start + $i * 3600,
                    'total' => 2, 'up' => 2,
                ]);
            }
        }

        app(RollupBuilder::class)->rebuildDaysFor($workspace, [$a->id]);

        $this->assertSame(2, CheckRollup::query()->where('bucket', 'day')->where('monitor_id', $a->id)->count());
        $this->assertSame(0, CheckRollup::query()->where('bucket', 'day')->where('monitor_id', $b->id)->count());
        $this->assertRollup($this->rollup($a->id, 'day', $start), ['total' => 48, 'up' => 48]);

        app(RollupBuilder::class)->rebuildDaysFor($workspace, [$b->id], $start + 86400);
        $this->assertSame(1, CheckRollup::query()->where('bucket', 'day')->where('monitor_id', $b->id)->count());
        $this->assertNull($this->rollup($b->id, 'day', $start));
    }
}
