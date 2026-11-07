<?php

namespace Tests\Feature\Database;

use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

class SqlitePragmaTest extends TestCase
{
    /** The file tempnam() created; the database lives next to it. */
    private string $base;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $base = tempnam(sys_get_temp_dir(), 'intrawatch-sqlite-');
        $this->assertIsString($base);
        $this->base = $base;
        $this->path = $base.'.sqlite';
        touch($this->path);

        /** @var array<string, mixed> $config */
        $config = config('database.connections.sqlite');
        config(['database.connections.sqlite_file' => ['database' => $this->path, 'url' => null] + $config]);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite_file');
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->path.$suffix);
        }
        @unlink($this->base);

        parent::tearDown();
    }

    private function pragma(string $name): string
    {
        $value = array_values((array) DB::connection('sqlite_file')->selectOne("pragma {$name}"))[0] ?? null;
        $this->assertIsScalar($value);

        return strtolower((string) $value);
    }

    public function test_file_database_uses_single_writer_friendly_pragmas(): void
    {
        $this->assertSame('wal', $this->pragma('journal_mode'));
        $this->assertSame('1', $this->pragma('synchronous'), 'synchronous must be NORMAL (1)');
        $this->assertSame('5000', $this->pragma('busy_timeout'));
    }

    public function test_transactions_take_the_write_lock_immediately(): void
    {
        $connection = DB::connection('sqlite_file');
        $this->assertInstanceOf(SQLiteConnection::class, $connection);
        $connection->statement('create table probe (id integer primary key)');

        $other = new PDO('sqlite:'.$this->path);
        $other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $other->exec('pragma busy_timeout = 0');

        $connection->beginTransaction();
        try {
            // A deferred transaction holds no lock until its first write, so this would succeed.
            $other->exec('BEGIN IMMEDIATE');
            $other->exec('ROLLBACK');
            $this->fail('A second writer acquired the lock while a transaction was open.');
        } catch (PDOException $e) {
            $this->assertStringContainsString('locked', strtolower($e->getMessage()));
        } finally {
            $connection->rollBack();
        }
    }
}
