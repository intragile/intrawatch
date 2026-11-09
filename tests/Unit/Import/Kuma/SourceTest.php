<?php

declare(strict_types=1);

namespace Tests\Unit\Import\Kuma;

use App\Import\Kuma\Source\KumaSource;
use App\Import\Kuma\Source\PdoKumaSource;
use App\Import\Kuma\Source\SourceFactory;
use App\Import\Kuma\Source\SourceKind;
use App\Import\Kuma\Source\UnreadableSource;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class SourceTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public static function fixture(string $name): string
    {
        return dirname(__DIR__, 4).'/tests/Fixtures/kuma/'.$name;
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma-test-');
        $this->assertNotFalse($path);
        file_put_contents($path, $contents);

        return $this->cleanup[] = $path;
    }

    private function scalar(PDO $pdo, string $sql): int
    {
        $stmt = $pdo->query($sql);
        $this->assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }

    private function copyOf(string $fixture): string
    {
        $contents = file_get_contents(self::fixture($fixture));
        $this->assertNotFalse($contents);

        return $this->tempFile($contents);
    }

    public static function assertKuma2Contents(KumaSource $source): void
    {
        $monitors = $source->monitors();
        self::assertCount(10, $monitors);
        self::assertSame(
            ['group', 'http', 'keyword', 'json-query', 'http', 'port', 'ping', 'dns', 'push', 'redis'],
            array_column($monitors, 'type'),
        );
        self::assertEquals(1, $monitors[1]['parent']);
        self::assertSame('==', $monitors[3]['json_path_operator']);

        $notifications = $source->notifications();
        self::assertCount(2, $notifications);
        self::assertSame('smtp', $notifications[0]['config']['type']);
        self::assertTrue($notifications[0]['is_default']);
        self::assertTrue($notifications[0]['active']);
        self::assertSame('webhook', $notifications[1]['config']['type']);
        self::assertFalse($notifications[1]['is_default']);

        self::assertCount(1, $source->tags());
        self::assertSame('prod', $source->tags()[0]['name']);
        self::assertCount(1, $source->statusPages());
        self::assertSame('public', $source->statusPages()[0]['slug']);
        self::assertCount(2, $source->statusPageGroups());
        self::assertCount(1, $source->statusPageDomains());
        self::assertSame('status.example.com', $source->statusPageDomains()[0]['domain']);
        self::assertCount(6, $source->maintenances());
        self::assertSame('stats', $source->historyKind());
        self::assertSame('2.x', $source->version());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $source->instanceKey());
    }

    public function test_detects_kinds(): void
    {
        $this->assertSame(SourceKind::Sqlite, SourceFactory::detect(self::fixture('kuma2.db')));
        $this->assertSame(SourceKind::Json, SourceFactory::detect(self::fixture('kuma1-backup.json')));

        $this->expectException(UnreadableSource::class);
        // Fixed bytes: random ones start with "{" after the trimmed BOM and whitespace bytes about once in 249 runs.
        SourceFactory::detect($this->tempFile("PK\x03\x04\x14\x00\x00\x00\x08\x00".str_repeat("\x00\xFF", 120)));
    }

    public function test_detection_only_looks_at_the_leading_bytes(): void
    {
        // A binary file that happens to start with "{" is taken for a backup; reading it as JSON then fails.
        $this->assertSame(SourceKind::Json, SourceFactory::detect($this->tempFile("\xEF\xBB\xBF \n{\x00\xFF\x9C")));
        $this->assertSame(SourceKind::Sqlite, SourceFactory::detect($this->tempFile("SQLite format 3\0\xFF\x00")));

        $this->expectExceptionObject(new UnreadableSource('The file is not valid JSON.'));
        SourceFactory::open($this->tempFile("\xEF\xBB\xBF \n{\x00\xFF\x9C"));
    }

    public function test_reads_kuma2_fixture(): void
    {
        $source = SourceFactory::open(self::fixture('kuma2.db'));
        $this->assertSame(SourceKind::Sqlite, $source->kind());
        self::assertKuma2Contents($source);

        $this->assertSame($source->instanceKey(), SourceFactory::open(self::fixture('kuma2.db'))->instanceKey());
        $this->assertNotSame($source->instanceKey(), SourceFactory::open(self::fixture('kuma1.db'))->instanceKey());
        $source->close();
    }

    public function test_reads_kuma2_relations_and_stats(): void
    {
        $source = SourceFactory::open(self::fixture('kuma2.db'));

        $this->assertNotEmpty($source->monitorNotifications());
        $this->assertSame(['monitor_id', 'notification_id'], array_keys($source->monitorNotifications()[0]));
        $this->assertNotEmpty($source->monitorTags());
        $this->assertNotEmpty($source->groupMonitors());
        $this->assertNotEmpty($source->maintenanceMonitors());
        $this->assertNotEmpty($source->maintenanceStatusPages());
        $this->assertNotEmpty($source->incidents());
        $this->assertNotEmpty($source->proxies());
        $this->assertIsString($source->setting('jwtSecret'));
        $this->assertSame([7, 14, 21], $source->setting('tlsExpiryNotifyDays'));
        $this->assertNull($source->setting('does-not-exist'));

        $hours = iterator_to_array($source->hourStats(), false);
        $days = iterator_to_array($source->dayStats(), false);
        $this->assertNotEmpty($hours);
        $this->assertNotEmpty($days);
        $this->assertSame(
            ['monitor_id', 'timestamp', 'up', 'down', 'maintenance', 'ping', 'ping_min', 'ping_max'],
            array_keys($hours[0]),
        );
        $this->assertSame([], iterator_to_array($source->heartbeats(9999), false));
        $source->close();
    }

    public function test_reads_kuma1_fixture(): void
    {
        $source = SourceFactory::open(self::fixture('kuma1.db'));
        $this->assertSame('1.23', $source->version());
        $this->assertSame('heartbeats', $source->historyKind());
        $monitors = $source->monitors();
        $this->assertCount(8, $monitors);
        $this->assertArrayHasKey('json_path_operator', $monitors[0]);
        $this->assertNull($monitors[0]['json_path_operator']);
        $this->assertNull($monitors[0]['conditions']);
        $this->assertSame([], iterator_to_array($source->hourStats(), false));

        $beats = iterator_to_array($source->heartbeats(2), false);
        $this->assertNotEmpty($beats);
        $this->assertSame(['status', 'ping', 'time'], array_keys($beats[0]));
        $times = array_column($beats, 'time');
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times);
        $this->assertGreaterThan(1_700_000_000, $times[0]);
        $source->close();
    }

    public function test_reads_json_backup(): void
    {
        $source = SourceFactory::open(self::fixture('kuma1-backup.json'));
        $this->assertSame(SourceKind::Json, $source->kind());
        $this->assertSame('1.23', $source->version());
        $this->assertSame(hash('sha256', 'kuma-json'), $source->instanceKey());
        $this->assertSame('none', $source->historyKind());
        $this->assertSame([], $source->proxies());
        $this->assertSame([], $source->statusPages());
        $this->assertSame([], $source->maintenances());
        $this->assertSame([], iterator_to_array($source->hourStats(), false));

        $monitors = $source->monitors();
        $this->assertCount(8, $monitors);
        $http = $monitors[1];
        $this->assertSame('Example HTTP', $http['name']);
        $this->assertSame(30, $http['retry_interval']);
        $this->assertSame(10, $http['resend_interval']);
        $this->assertSame(1, $http['expiry_notification']);
        $this->assertSame('basic', $http['auth_method']);
        $this->assertSame('["200-299","301"]', $http['accepted_statuscodes_json']);
        $this->assertArrayNotHasKey('retryInterval', $http);
        $this->assertArrayNotHasKey('tags', $http);
        $this->assertNull($http['json_path_operator']);

        $this->assertContains(
            ['monitor_id' => 2, 'notification_id' => 2],
            $source->monitorNotifications(),
        );
        $this->assertCount(2, $source->monitorNotifications());
        $this->assertSame('smtp', $source->notifications()[0]['config']['type']);
        $this->assertCount(2, $source->notifications());

        $this->assertSame([['id' => 1, 'name' => 'prod', 'color' => '#059669']], $source->tags());
        $this->assertContains(['monitor_id' => 2, 'tag_id' => 1, 'value' => 'eu-west'], $source->monitorTags());
        $this->assertContains(['monitor_id' => 5, 'tag_id' => 1, 'value' => ''], $source->monitorTags());
        $this->assertSame(['users' => 0, 'api_keys' => 0, 'docker_hosts' => 0, 'remote_browsers' => 0], $source->notMigratedCounts());
    }

    public function test_factory_rejects_garbage_non_kuma_and_corrupt(): void
    {
        $this->assertRejected($this->tempFile(random_bytes(512)));
        $this->assertRejected($this->tempFile('{"hello": "world"}'));
        $this->assertRejected($this->tempFile('{not json'));
        $this->assertRejected(self::fixture('does-not-exist.db'));
        $this->assertRejected('mysql://nobody:nothing@127.0.0.1:1/none');

        $empty = $this->tempFile('');
        (new PDO('sqlite:'.$empty))->exec('CREATE TABLE other (id INTEGER)');
        $this->assertRejected($empty);

        $whole = file_get_contents(self::fixture('kuma2.db'));
        $this->assertNotFalse($whole);
        $this->assertRejected($this->tempFile(substr($whole, 0, (int) (strlen($whole) / 3))));
        $damaged = $whole;
        for ($i = 4096 * 3; $i < 4096 * 3 + 64; $i++) {
            $damaged[$i] = "\xFF";
        }
        $this->assertRejected($this->tempFile($damaged));
    }

    private function assertRejected(string $source): void
    {
        try {
            SourceFactory::open($source)->monitors();
            $this->fail('Expected UnreadableSource for '.basename($source));
        } catch (UnreadableSource $e) {
            $this->assertNotSame('', $e->getMessage());
            $this->assertStringNotContainsString('nothing@', $e->getMessage());
        }
    }

    /**
     * A database whose tables Kuma reads are created by the given statements.
     *
     * @param  list<string>  $statements
     */
    private function database(array $statements): string
    {
        $path = $this->tempFile('');
        $pdo = new PDO('sqlite:'.$path);
        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }

        return $path;
    }

    private function openError(string $path): string
    {
        try {
            SourceFactory::open($path)->close();
        } catch (UnreadableSource $e) {
            return $e->getMessage();
        }
        $this->fail('The database was accepted.');
    }

    public function test_virtual_monitor_table_is_rejected(): void
    {
        $path = $this->database([
            'CREATE TABLE setting (id INTEGER PRIMARY KEY, "key" TEXT, value TEXT)',
            'CREATE VIRTUAL TABLE monitor USING fts5(name, type, url)',
            "INSERT INTO monitor (name, type, url) VALUES ('m', 'http', 'https://example.com')",
        ]);

        $this->assertStringContainsString('virtual table (monitor)', $this->openError($path));
    }

    public function test_any_virtual_table_kuma_reads_is_rejected_when_opened(): void
    {
        $path = $this->database([
            'CREATE TABLE setting (id INTEGER PRIMARY KEY, "key" TEXT, value TEXT)',
            'CREATE TABLE monitor (id INTEGER PRIMARY KEY, name TEXT, type TEXT)',
            'create  virtual table tag using fts5(name, color)',
        ]);

        $this->assertStringContainsString('virtual table (tag)', $this->openError($path));
    }

    public function test_virtual_tables_kuma_does_not_read_are_ignored(): void
    {
        $path = $this->database([
            'CREATE TABLE setting (id INTEGER PRIMARY KEY, "key" TEXT, value TEXT)',
            'CREATE TABLE monitor (id INTEGER PRIMARY KEY, name TEXT, type TEXT)',
            'CREATE VIRTUAL TABLE search_index USING fts5(body)',
        ]);

        $source = SourceFactory::open($path);
        $this->assertSame([], $source->monitors());
        $source->close();
    }

    public function test_wal_copy_without_sidecar_opens(): void
    {
        $path = $this->copyOf('kuma2.db');
        $handle = fopen($path, 'r+b');
        $this->assertNotFalse($handle);
        fseek($handle, 18);
        fwrite($handle, "\x02\x02");
        fclose($handle);

        $source = SourceFactory::open($path);
        $this->assertCount(10, $source->monitors());
        $source->close();
        $this->assertFileDoesNotExist($path.'-wal');
    }

    public function test_source_is_read_only_and_ignores_triggers(): void
    {
        $path = $this->copyOf('kuma2.db');
        $pdo = new PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE audit_x (n INTEGER)');
        $pdo->exec('CREATE TRIGGER monitor_t AFTER INSERT ON monitor BEGIN INSERT INTO audit_x VALUES (1); END');
        $pdo->exec('CREATE VIEW monitor_x AS SELECT * FROM monitor');
        $pdo = null;

        $before = glob(sys_get_temp_dir().'/kuma-import-*') ?: [];
        $source = SourceFactory::open($path);
        $this->assertInstanceOf(PdoKumaSource::class, $source);
        $this->assertCount(10, $source->monitors());
        $during = glob(sys_get_temp_dir().'/kuma-import-*') ?: [];
        $this->assertCount(count($before) + 1, $during);

        $pdo = (new ReflectionProperty(PdoKumaSource::class, 'pdo'))->getValue($source);
        $this->assertInstanceOf(PDO::class, $pdo);
        $this->assertSame(1, $this->scalar($pdo, 'PRAGMA query_only'));
        $this->assertSame(0, $this->scalar($pdo, 'PRAGMA trusted_schema'));
        try {
            $pdo->exec("INSERT INTO setting (key, value) VALUES ('x', 'y')");
            $this->fail('A write through the source must fail.');
        } catch (PDOException) {
            $this->addToAssertionCount(1);
        }

        $source->close();
        $this->assertEqualsCanonicalizing($before, glob(sys_get_temp_dir().'/kuma-import-*') ?: []);
        $this->assertFileExists($path);
        $count = (new PDO('sqlite:'.$path))->query('SELECT COUNT(*) FROM audit_x');
        $this->assertNotFalse($count);
        $this->assertSame('0', (string) $count->fetchColumn());
    }

    public function test_not_migrated_counts(): void
    {
        $source = SourceFactory::open(self::fixture('kuma2.db'));
        $this->assertSame(
            ['users' => 1, 'api_keys' => 1, 'docker_hosts' => 1, 'remote_browsers' => 0],
            $source->notMigratedCounts(),
        );
        $source->close();

        $old = SourceFactory::open(self::fixture('kuma1.db'));
        $this->assertSame(0, $old->notMigratedCounts()['remote_browsers']);
        $this->assertSame(1, $old->notMigratedCounts()['users']);
        $old->close();
    }

    public function test_failed_opens_leave_no_temp_copy(): void
    {
        $before = glob(sys_get_temp_dir().'/kuma-import-*') ?: [];

        foreach ([self::fixture('missing.db'), $this->tempFile(substr((string) file_get_contents(self::fixture('kuma2.db')), 0, 20000))] as $bad) {
            try {
                PdoKumaSource::sqlite($bad);
                $this->fail('Expected UnreadableSource.');
            } catch (UnreadableSource) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertEqualsCanonicalizing($before, glob(sys_get_temp_dir().'/kuma-import-*') ?: []);
    }

    public function test_malformed_heartbeat_time_is_skipped(): void
    {
        $path = $this->copyOf('kuma1.db');
        $pdo = new PDO('sqlite:'.$path);
        $pdo->exec("UPDATE heartbeat SET time = 'not a time' WHERE id = (SELECT MIN(id) FROM heartbeat WHERE monitor_id = 2)");
        $total = $this->scalar($pdo, 'SELECT COUNT(*) FROM heartbeat WHERE monitor_id = 2');
        $pdo = null;

        $source = SourceFactory::open($path);
        $this->assertCount($total - 1, iterator_to_array($source->heartbeats(2), false));
        $source->close();
    }

    public function test_json_backup_with_bom_is_read(): void
    {
        $raw = (string) file_get_contents(self::fixture('kuma1-backup.json'));
        $path = $this->tempFile("\xEF\xBB\xBF".$raw);

        $this->assertSame(SourceKind::Json, SourceFactory::detect($path));
        $this->assertCount(8, SourceFactory::open($path)->monitors());
    }
}
