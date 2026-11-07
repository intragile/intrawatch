<?php

declare(strict_types=1);

namespace Tests\Feature\Import\Kuma;

use App\Import\Kuma\Source\SourceFactory;
use App\Import\Kuma\Source\SourceKind;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Import\Kuma\SourceTest;

final class MysqlSourceTest extends TestCase
{
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

    public function test_reads_mariadb_dump(): void
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

        $this->assertSame(SourceKind::Mysql, $source->kind());
        SourceTest::assertKuma2Contents($source);
        $this->assertSame(
            ['users' => 1, 'api_keys' => 1, 'docker_hosts' => 1, 'remote_browsers' => 0],
            $source->notMigratedCounts(),
        );
        $source->close();
    }
}
