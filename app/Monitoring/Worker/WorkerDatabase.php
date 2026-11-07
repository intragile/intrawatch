<?php

namespace App\Monitoring\Worker;

use Illuminate\Support\Facades\DB;
use PDO;
use Pdo\Mysql;

/**
 * Connection settings for the check worker, whose database calls block the event loop.
 *
 * The defaults let a statement wait for a lock for seconds (SQLite busy timeout 5 s, InnoDB lock wait 50 s)
 * or for a dead host until the operating system gives up. In the worker every second of such a wait stalls
 * all checks in flight, inflates their latency and can push the shutdown drain past the stop grace period.
 * The outcome buffer retries failed writes anyway, so the worker gives up quickly instead.
 */
final class WorkerDatabase
{
    public const SQLITE_BUSY_TIMEOUT_MS = 500;

    public const LOCK_WAIT_SECONDS = 3;

    public const CONNECT_TIMEOUT_SECONDS = 3;

    public const STATEMENT_TIMEOUT = '30s';

    /**
     * The given connection configuration with the worker's short lock, connect and statement timeouts.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function configure(array $config): array
    {
        $options = is_array($config['options'] ?? null) ? $config['options'] : [];
        $serverOptions = is_array($config['server_options'] ?? null) ? $config['server_options'] : [];

        return match ($config['driver'] ?? null) {
            'sqlite' => ['busy_timeout' => self::SQLITE_BUSY_TIMEOUT_MS] + $config,
            'mysql', 'mariadb' => ['options' => array_replace($options, [
                PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                Mysql::ATTR_INIT_COMMAND => 'SET SESSION innodb_lock_wait_timeout='.self::LOCK_WAIT_SECONDS,
            ])] + $config,
            'pgsql' => [
                'options' => array_replace($options, [PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS]),
                'server_options' => array_replace($serverOptions, [
                    'lock_timeout' => self::LOCK_WAIT_SECONDS.'s',
                    'statement_timeout' => self::STATEMENT_TIMEOUT,
                ]),
            ] + $config,
            default => $config,
        };
    }

    /**
     * Applies configure() to the default connection and drops the open connection, so the next query and
     * every reconnect after a failure use the new settings.
     */
    public static function apply(): void
    {
        $name = config()->string('database.default');
        $config = config("database.connections.{$name}");
        if (! is_array($config)) {
            return;
        }

        /** @var array<string, mixed> $config */
        config(["database.connections.{$name}" => self::configure($config)]);
        DB::purge($name);
    }
}
