<?php

namespace Tests\Feature\Monitoring;

use App\Monitoring\Worker\WorkerDatabase;
use Illuminate\Support\Facades\Artisan;
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

    /**
     * Runs without RefreshDatabase: the worker reconnects the default connection, which would discard the
     * in-memory test database.
     */
    public function test_the_worker_command_applies_them_and_refuses_the_sync_queue(): void
    {
        $name = config()->string('database.default');
        $original = config("database.connections.{$name}");
        $queue = config('queue.default');

        try {
            // The sync queue makes the worker exit before its scheduler starts, after the connection is set up.
            config(['queue.default' => 'sync']);
            $this->assertSame(1, Artisan::call('intrawatch:worker'));
            $this->assertStringContainsString('QUEUE_CONNECTION is sync', Artisan::output());

            $this->assertIsArray($original);
            $configured = [];
            foreach ($original as $key => $value) {
                $configured[(string) $key] = $value;
            }
            $this->assertSame(WorkerDatabase::configure($configured), config("database.connections.{$name}"));
            $this->assertNotSame($original, config("database.connections.{$name}"));
        } finally {
            config(['queue.default' => $queue, "database.connections.{$name}" => $original]);
            DB::purge($name);
        }
    }
}
