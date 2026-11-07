<?php

namespace Tests\Feature\Monitoring;

use App\Monitoring\Worker\WorkerDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Pdo\Mysql;
use Tests\TestCase;

class WorkerDatabaseTest extends TestCase
{
    public function test_worker_connections_give_up_on_locks_quickly(): void
    {
        $sqlite = WorkerDatabase::configure(['driver' => 'sqlite', 'busy_timeout' => 5000]);
        $this->assertSame(500, $sqlite['busy_timeout']);

        $mysql = WorkerDatabase::configure(['driver' => 'mysql', 'options' => [Mysql::ATTR_SSL_CA => null]]);
        $this->assertIsArray($mysql['options']);
        $this->assertSame(3, $mysql['options'][PDO::ATTR_TIMEOUT]);
        $this->assertSame('SET SESSION innodb_lock_wait_timeout=3', $mysql['options'][Mysql::ATTR_INIT_COMMAND]);
        $this->assertArrayHasKey(Mysql::ATTR_SSL_CA, $mysql['options'], 'configured options are kept');

        $pgsql = WorkerDatabase::configure(['driver' => 'pgsql', 'server_options' => ['work_mem' => '4MB']]);
        $this->assertSame(['work_mem' => '4MB', 'lock_timeout' => '3s', 'statement_timeout' => '30s'], $pgsql['server_options']);
    }

    public function test_the_default_connection_reconnects_with_them(): void
    {
        $name = config()->string('database.default');
        $original = config("database.connections.{$name}");

        try {
            WorkerDatabase::apply();
            $driver = DB::connection()->getDriverName();
            $value = match ($driver) {
                'sqlite' => array_values((array) DB::selectOne('pragma busy_timeout'))[0] ?? null,
                'pgsql' => DB::scalar('show lock_timeout'),
                default => DB::scalar('select @@session.innodb_lock_wait_timeout'),
            };
            $this->assertIsScalar($value);
            $this->assertSame(['sqlite' => '500', 'pgsql' => '3s'][$driver] ?? '3', (string) $value);
        } finally {
            config(["database.connections.{$name}" => $original]);
            DB::purge($name);
        }
    }
}
