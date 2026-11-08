<?php

declare(strict_types=1);

namespace Tests\Feature\Import\Kuma;

use App\Import\Kuma\ImportOptions;
use App\Import\Kuma\KumaImporter;
use App\Import\Kuma\Source\KumaSource;
use App\Import\Kuma\Source\SourceFactory;
use App\Import\Kuma\Source\SourceKind;
use App\Models\CheckRollup;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;
use Tests\Unit\Import\Kuma\SourceTest;

final class MysqlSourceTest extends TestCase
{
    use RefreshDatabase;

    private const DATABASE = 'kuma_fixture';

    private ?PDO $admin = null;

    protected function tearDown(): void
    {
        if ($this->admin !== null) {
            $this->admin->exec('DROP DATABASE IF EXISTS '.self::DATABASE);
        }
        parent::tearDown();
    }

    /**
     * MariaDB accepts literal defaults on TEXT columns; MySQL 8.4 rejects them (error 1101). The rows in the dump
     * carry every value, so dropping those defaults does not change what is read.
     */
    private static function forMysql(string $dump): string
    {
        return (string) preg_replace(
            "/^(\\s+`\\w+` (?:tiny|medium|long)?text(?: NOT NULL)?) DEFAULT '(?:[^']|'')*'/m",
            '$1',
            $dump,
        );
    }

    private function openFixture(): KumaSource
    {
        $dsn = getenv('KUMA_MYSQL_TEST_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('KUMA_MYSQL_TEST_DSN is not set.');
        }

        $parts = parse_url($dsn);
        $this->assertIsArray($parts);
        $this->admin = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $parts['host'] ?? '127.0.0.1', $parts['port'] ?? 3306),
            urldecode($parts['user'] ?? 'root'),
            urldecode($parts['pass'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->admin->exec('DROP DATABASE IF EXISTS '.self::DATABASE);
        $this->admin->exec('CREATE DATABASE '.self::DATABASE.' CHARACTER SET utf8mb4');
        $this->admin->exec('USE '.self::DATABASE);
        $dump = file_get_contents(SourceTest::fixture('kuma2-mariadb.sql'));
        $this->assertNotFalse($dump);
        $this->admin->exec(self::forMysql($dump));

        $source = SourceFactory::open(sprintf(
            'mysql://%s:%s@%s:%d/%s',
            rawurlencode(urldecode($parts['user'] ?? 'root')),
            rawurlencode(urldecode($parts['pass'] ?? '')),
            $parts['host'] ?? '127.0.0.1',
            $parts['port'] ?? 3306,
            self::DATABASE,
        ));

        return $source;
    }

    public function test_reads_mariadb_dump(): void
    {
        $source = $this->openFixture();

        $this->assertSame(SourceKind::Mysql, $source->kind());
        SourceTest::assertKuma2Contents($source);
        $this->assertSame(
            ['users' => 1, 'api_keys' => 1, 'docker_hosts' => 1, 'remote_browsers' => 0],
            $source->notMigratedCounts(),
        );
        $source->close();
    }

    public function test_imports_history_through_the_unbuffered_connection(): void
    {
        CarbonImmutable::setTestNow('2026-11-08 12:00:00 UTC');
        $source = $this->openFixture();
        $workspace = Workspace::factory()->create()->refresh();

        $report = app(KumaImporter::class)->run($source, $workspace, new ImportOptions, null)->toArray();

        $this->assertSame(7, $report['history']['monitors']);
        $this->assertSame(7, $report['history']['hour_rows']);
        $this->assertSame(7, $report['history']['day_rows']);
        $this->assertSame([], array_filter($report['history']['notes'], fn (array $n): bool => $n['code'] === 'history_failed'));
        $this->assertSame(7, CheckRollup::query()->where('bucket', 'hour')->count());
        $this->assertNotEmpty(iterator_to_array($source->heartbeats(1), false));
        $this->assertSame('Websites', $source->monitors()[0]['name']);
        $source->close();
        CarbonImmutable::setTestNow();
    }
}
