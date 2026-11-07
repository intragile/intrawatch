<?php

namespace Tests\Feature\Database;

use PDO;
use Tests\TestCase;

/**
 * An unreachable database host must fail fast instead of holding a request for the operating system's TCP
 * connect timeout.
 */
class ConnectTimeoutTest extends TestCase
{
    public function test_mysql_and_mariadb_connections_have_a_connect_timeout(): void
    {
        foreach (['mysql', 'mariadb'] as $connection) {
            $options = config()->array("database.connections.{$connection}.options");
            $this->assertSame(3, $options[PDO::ATTR_TIMEOUT] ?? null, $connection);
        }
    }

    public function test_pgsql_connection_has_a_connect_timeout(): void
    {
        // pdo_pgsql hands PDO::ATTR_TIMEOUT to libpq as its connect_timeout.
        $this->assertSame(3, config()->array('database.connections.pgsql.options')[PDO::ATTR_TIMEOUT] ?? null);
    }

    public function test_the_timeout_follows_the_environment(): void
    {
        putenv('DB_CONNECT_TIMEOUT=5');
        try {
            $config = require config_path('database.php');
        } finally {
            putenv('DB_CONNECT_TIMEOUT');
        }
        $this->assertIsArray($config);
        $connections = $config['connections'] ?? null;
        $this->assertIsArray($connections);
        foreach (['mysql', 'pgsql'] as $connection) {
            $settings = $connections[$connection] ?? null;
            $this->assertIsArray($settings);
            $options = $settings['options'] ?? null;
            $this->assertIsArray($options);
            $this->assertSame(5, $options[PDO::ATTR_TIMEOUT] ?? null, $connection);
        }
    }
}
