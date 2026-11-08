<?php

declare(strict_types=1);

namespace Tests\Bench;

use App\Import\Kuma\ImportContext;
use App\Import\Kuma\ImportOptions;
use App\Import\Kuma\KumaImporter;
use App\Import\Kuma\Source\SourceFactory;
use App\Import\Kuma\Steps\ImportStep;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Imports a synthetic Uptime Kuma 2.x instance of production size on the configured database connection and
 * fails when the import takes 300 seconds or more. Run it on its own:
 *
 *     vendor/bin/phpunit --testsuite Bench --group bench
 *
 * KUMA_BENCH_MONITORS scales the instance down for a quick run; the statistics always cover a tenth of it.
 */
#[Group('bench')]
final class KumaImportBench extends TestCase
{
    use DatabaseMigrations;

    private const MAX_SECONDS = 300.0;

    private const GROUPS = 250;

    private const PAGES = 300;

    private const ITEMS = 20;

    private const TAGS = 50;

    private const NOTIFICATIONS = 15;

    private const HOUR_DAYS = 30;

    private const DAYS = 365;

    private const CHUNK = 500;

    private string $path = '';

    protected function tearDown(): void
    {
        if ($this->path !== '') {
            @unlink($this->path);
        }
        parent::tearDown();
    }

    public function test_import_of_a_large_instance_finishes_in_time(): void
    {
        $monitors = max(100, (int) (getenv('KUMA_BENCH_MONITORS') ?: 5000));
        $withStats = intdiv($monitors, 10);

        $started = hrtime(true);
        $this->path = $this->generate($monitors, $withStats);
        $generateSeconds = (hrtime(true) - $started) / 1e9;

        $workspace = Workspace::factory()->create(['timezone' => 'UTC'])->refresh();
        /** @var array<string, float> $steps */
        $steps = [];
        foreach (KumaImporter::STEPS as $class) {
            $record = static function (float $seconds) use ($class, &$steps): void {
                $steps[$class] = ($steps[$class] ?? 0.0) + $seconds;
            };
            $this->app->extend($class, static fn (ImportStep $inner): ImportStep => new class($inner, $record) implements ImportStep
            {
                /**
                 * @param  \Closure(float): void  $record
                 */
                public function __construct(private readonly ImportStep $inner, private readonly \Closure $record) {}

                public function run(ImportContext $ctx): void
                {
                    $started = hrtime(true);
                    try {
                        $this->inner->run($ctx);
                    } finally {
                        ($this->record)((hrtime(true) - $started) / 1e9);
                    }
                }
            });
        }

        $memoryBefore = memory_get_peak_usage(true);
        $started = hrtime(true);
        $source = SourceFactory::open($this->path);
        try {
            $report = app(KumaImporter::class)->run($source, $workspace, new ImportOptions, null);
        } finally {
            $source->close();
        }
        $seconds = (hrtime(true) - $started) / 1e9;

        $lines = [
            sprintf('Kuma import bench on %s: %d monitors + %d groups, %d pages x %d items, stats for %d monitors (%d hours, %d days)',
                DB::connection()->getDriverName(), $monitors, self::GROUPS, self::PAGES, self::ITEMS, $withStats, self::HOUR_DAYS * 24, self::DAYS),
            sprintf('  %-28s %8.2f s', 'generate source', $generateSeconds),
        ];
        foreach ($steps as $class => $stepSeconds) {
            $lines[] = sprintf('  %-28s %8.2f s', class_basename($class), $stepSeconds);
        }
        $lines[] = sprintf('  %-28s %8.2f s', 'import total', $seconds);
        $lines[] = sprintf('  %-28s %8.1f MB (before import %.1f MB)', 'peak memory', memory_get_peak_usage(true) / 1048576, $memoryBefore / 1048576);
        fwrite(STDERR, "\n".implode("\n", $lines)."\n");

        $counts = $report->counts();
        $this->assertSame($monitors + self::GROUPS, $counts['monitor']['created'] ?? null, json_encode($counts, JSON_THROW_ON_ERROR));
        $this->assertSame(self::PAGES, $counts['status_page']['created'] ?? null, json_encode($counts, JSON_THROW_ON_ERROR));
        $this->assertSame($monitors + self::GROUPS, DB::table('monitors')->where('workspace_id', $workspace->id)->count());
        $this->assertSame(self::PAGES * self::ITEMS, DB::table('status_page_items')->count());
        $this->assertGreaterThan(0, DB::table('check_rollups')->count());
        $this->assertLessThan(self::MAX_SECONDS, $seconds, "The import took {$seconds} s.");
    }

    /**
     * Builds the Kuma database from the recorded 2.x schema; returns its path.
     */
    private function generate(int $monitors, int $withStats): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'kuma-bench-');
        $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = (string) file_get_contents(base_path('tests/Fixtures/kuma/kuma2-schema.sql'));
        // sqlite_sequence is created by SQLite itself and cannot be created explicitly.
        $pdo->exec(str_replace('CREATE TABLE sqlite_sequence(name,seq);', '', $schema));
        $pdo->exec('PRAGMA journal_mode = OFF');
        $pdo->exec('PRAGMA synchronous = OFF');
        $pdo->beginTransaction();

        $now = time();

        $this->insert($pdo, 'setting', ['key', 'value', 'type'], 2, static fn (int $i): array => $i === 0
            ? ['jwtSecret', bin2hex(random_bytes(16)), null]
            : ['primaryBaseURL', 'https://status.example.test', 'general']);
        $this->insert($pdo, 'user', ['username', 'password'], 1, static fn (): array => ['admin', 'x']);
        $this->insert($pdo, 'tag', ['name', 'color'], self::TAGS, static fn (int $i): array => [sprintf('tag-%02d', $i + 1), '#059669']);
        $this->insert($pdo, 'notification', ['name', 'active', 'user_id', 'is_default', 'config'], self::NOTIFICATIONS, static fn (int $i): array => [
            'hook '.($i + 1), 1, 1, 0,
            json_encode(['name' => 'hook '.($i + 1), 'type' => 'webhook', 'webhookURL' => 'https://hooks.example.test/'.($i + 1), 'webhookContentType' => 'json'], JSON_THROW_ON_ERROR),
        ]);

        // Groups take ids 1..GROUPS: the first fifth at the top, the rest nested one level below.
        $top = intdiv(self::GROUPS, 5);
        $columns = ['name', 'active', 'user_id', 'interval', 'url', 'type', 'hostname', 'port', 'keyword', 'maxretries', 'dns_resolve_type', 'dns_resolve_server', 'push_token', 'parent', 'timeout', 'retry_interval'];
        $this->insert($pdo, 'monitor', $columns, self::GROUPS, static fn (int $i): array => [
            sprintf('group %03d', $i + 1), 1, 1, 60, null, 'group', null, null, null, 0, 'A', '1.1.1.1', null, $i < $top ? null : ($i % $top) + 1, 48, 60,
        ]);
        $this->insert($pdo, 'monitor', $columns, $monitors, static function (int $i): array {
            $parent = $i % 5 === 0 ? null : ($i % self::GROUPS) + 1;
            $name = sprintf('service %05d', $i + 1);

            return match ($i % 10) {
                6 => [$name, 1, 1, 60, null, 'port', 'db'.$i.'.example.test', 5432, null, 1, 'A', '1.1.1.1', null, $parent, 48, 60],
                7 => [$name, 1, 1, 60, null, 'ping', 'host'.$i.'.example.test', null, null, 1, 'A', '1.1.1.1', null, $parent, 48, 60],
                8 => [$name, 1, 1, 300, null, 'dns', 'svc'.$i.'.example.test', 53, null, 0, 'A', '1.1.1.1', null, $parent, 48, 300],
                9 => [$name, 1, 1, 60, null, 'push', null, null, null, 0, 'A', '1.1.1.1', sprintf('benchPushToken%05d', $i), $parent, 48, 60],
                5 => [$name, 1, 1, 60, 'https://app'.$i.'.example.test/health', 'keyword', null, null, 'ok', 1, 'A', '1.1.1.1', null, $parent, 48, 60],
                default => [$name, 1, 1, 60, 'https://app'.$i.'.example.test/', 'http', null, null, null, 1, 'A', '1.1.1.1', null, $parent, 48, 60],
            };
        });
        $first = self::GROUPS + 1;
        $this->insert($pdo, 'monitor_tag', ['monitor_id', 'tag_id', 'value'], $monitors, static fn (int $i): array => [$first + $i, ($i % self::TAGS) + 1, $i % 4 === 0 ? 'eu-west' : null]);
        $this->insert($pdo, 'monitor_notification', ['monitor_id', 'notification_id'], $monitors, static fn (int $i): array => [$first + $i, ($i % self::NOTIFICATIONS) + 1]);

        $this->insert($pdo, 'status_page', ['slug', 'title', 'description', 'icon', 'theme', 'published'], self::PAGES, static fn (int $i): array => [
            'page-'.($i + 1), 'Status '.($i + 1), null, '/icon.svg', 'light', 1,
        ]);
        $this->insert($pdo, '`group`', ['name', 'public', 'active', 'weight', 'status_page_id'], self::PAGES, static fn (int $i): array => ['Services', 1, 1, 1, $i + 1]);
        $this->insert($pdo, 'monitor_group', ['monitor_id', 'group_id', 'weight'], self::PAGES * self::ITEMS, static fn (int $i): array => [
            $first + ($i % $monitors), intdiv($i, self::ITEMS) + 1, ($i % self::ITEMS) + 1,
        ]);

        $hour = intdiv($now, 3600) * 3600;
        $hours = self::HOUR_DAYS * 24;
        $statColumns = ['monitor_id', 'timestamp', 'ping', 'ping_min', 'ping_max', 'up', 'down'];
        $this->insert($pdo, 'stat_hourly', $statColumns, $withStats * $hours, static fn (int $i): array => [
            $first + intdiv($i, $hours), $hour - ($i % $hours) * 3600, 120.5, 40.0, 400.0, 60 - ($i % 3 === 0 ? 1 : 0), $i % 3 === 0 ? 1 : 0,
        ]);
        $day = intdiv($now, 86400) * 86400;
        $this->insert($pdo, 'stat_daily', $statColumns, $withStats * self::DAYS, static fn (int $i): array => [
            $first + intdiv($i, self::DAYS), $day - ($i % self::DAYS) * 86400, 118.25, 35.0, 900.0, 1438, 2,
        ]);

        $pdo->commit();

        return $path;
    }

    /**
     * Inserts rows in multi-row statements of at most CHUNK rows.
     *
     * @param  list<string>  $columns
     * @param  callable(int): list<mixed>  $row
     */
    private function insert(PDO $pdo, string $table, array $columns, int $count, callable $row): void
    {
        for ($start = 0; $start < $count; $start += self::CHUNK) {
            $n = min(self::CHUNK, $count - $start);
            $placeholders = implode(',', array_fill(0, $n, '('.implode(',', array_fill(0, count($columns), '?')).')'));
            $values = [];
            for ($i = $start; $i < $start + $n; $i++) {
                array_push($values, ...$row($i));
            }
            $pdo->prepare("INSERT INTO {$table} (".implode(',', $columns).") VALUES {$placeholders}")->execute($values);
        }
    }
}
