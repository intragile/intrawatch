<?php

declare(strict_types=1);

namespace App\Import\Kuma\Source;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Generator;
use PDO;
use Pdo\Mysql;
use Pdo\Sqlite;
use PDOException;
use Throwable;

/**
 * Reads a Kuma SQLite or MariaDB/MySQL database. SQLite files are opened as a private read-only temp copy;
 * only whitelisted tables are read, with explicit column lists.
 */
final class PdoKumaSource implements KumaSource
{
    private const SQLITE_MAGIC = "SQLite format 3\0";

    /** Every table read from a source; in SQLite each must be a regular table. */
    private const TABLES = [
        'api_key', 'docker_host', 'group', 'heartbeat', 'incident', 'maintenance', 'maintenance_status_page',
        'monitor', 'monitor_group', 'monitor_maintenance', 'monitor_notification', 'monitor_tag', 'notification',
        'proxy', 'remote_browser', 'setting', 'stat_daily', 'stat_hourly', 'status_page', 'status_page_cname',
        'tag', 'user',
    ];

    /** @var array<string, bool> */
    private array $tableCache = [];

    /** @var array<string, list<string>> */
    private array $columnCache = [];

    private ?string $tempPath;

    private function __construct(
        private readonly PDO $pdo,
        private readonly SourceKind $kind,
        ?string $tempPath = null,
    ) {
        $this->tempPath = $tempPath;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Opens a read-only copy of the database in $tempDir (created when missing; the system temp directory when
     * null). The copy is removed by close().
     *
     * @throws UnreadableSource
     */
    public static function sqlite(string $path, ?string $tempDir = null): self
    {
        $dir = $tempDir ?? sys_get_temp_dir();
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new UnreadableSource('The source file cannot be copied for reading.');
        }
        $temp = @tempnam($dir, 'kuma-import-');
        // tempnam() silently falls back to the system temp directory when $dir is not writable.
        if ($temp !== false && dirname($temp) !== (realpath($dir) ?: $dir)) {
            @unlink($temp);
            $temp = false;
        }
        if ($temp === false) {
            throw new UnreadableSource('The source file cannot be copied for reading.');
        }

        try {
            if (! @copy($path, $temp)) {
                throw new UnreadableSource('The source file cannot be copied for reading.');
            }
            self::clearWalMode($temp);
            $pdo = Sqlite::connect('sqlite:'.$temp, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                Sqlite::ATTR_OPEN_FLAGS => Sqlite::OPEN_READONLY,
            ]);
            $pdo->exec('PRAGMA trusted_schema = OFF');
            $pdo->exec('PRAGMA query_only = ON');
            $check = $pdo->query('PRAGMA quick_check(1)');
            if ($check === false || $check->fetchColumn() !== 'ok') {
                throw new UnreadableSource('The SQLite file is corrupt.');
            }
            $source = new self($pdo, SourceKind::Sqlite, $temp);
            $source->assertKuma();

            return $source;
        } catch (UnreadableSource $e) {
            @unlink($temp);
            throw $e;
        } catch (Throwable) {
            @unlink($temp);
            throw new UnreadableSource('The SQLite file is corrupt or is not a readable database.');
        }
    }

    /**
     * @throws UnreadableSource
     */
    public static function mysql(string $dsn): self
    {
        $parts = parse_url($dsn);
        if ($parts === false || ! isset($parts['host'], $parts['path']) || trim($parts['path'], '/') === '') {
            throw new UnreadableSource('The MySQL source must look like mysql://user:password@host:port/database.');
        }

        try {
            $pdo = new Mysql(
                sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $parts['host'],
                    $parts['port'] ?? 3306,
                    rawurldecode(trim($parts['path'], '/')),
                ),
                rawurldecode($parts['user'] ?? ''),
                rawurldecode($parts['pass'] ?? ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 10,
                    // History is streamed row by row; every other read fetches its result completely, so no
                    // second statement runs while a cursor is open.
                    Mysql::ATTR_USE_BUFFERED_QUERY => false,
                ],
            );
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
            $source = new self($pdo, SourceKind::Mysql);
            $source->assertKuma();

            return $source;
        } catch (PDOException) {
            throw new UnreadableSource('The MySQL/MariaDB database cannot be reached with the given DSN.');
        }
    }

    /**
     * A WAL-mode file without its -wal sidecar only opens once the header says rollback-journal mode.
     *
     * @throws UnreadableSource
     */
    private static function clearWalMode(string $path): void
    {
        $handle = @fopen($path, 'r+b');
        if ($handle === false) {
            throw new UnreadableSource('The source file cannot be prepared for reading.');
        }
        $ok = true;
        if (@fread($handle, 16) === self::SQLITE_MAGIC) {
            $ok = @fseek($handle, 18) === 0;
            $flags = $ok ? @fread($handle, 2) : false;
            if ($flags === false) {
                $ok = false;
            } elseif ($flags === "\x02\x02") {
                $ok = @fseek($handle, 18) === 0 && @fwrite($handle, "\x01\x01") === 2;
            }
        }
        fclose($handle);
        if (! $ok) {
            throw new UnreadableSource('The source file cannot be prepared for reading.');
        }
    }

    /**
     * @throws UnreadableSource
     */
    private function assertKuma(): void
    {
        if (! $this->hasTable('monitor') || ! $this->hasTable('setting')) {
            throw new UnreadableSource('The database is not an Uptime Kuma database (no monitor table).');
        }
        // Looks up every table now, so a virtual one is rejected when the source is opened, not halfway through.
        foreach (self::TABLES as $table) {
            $this->hasTable($table);
        }
    }

    public function close(): void
    {
        if ($this->tempPath !== null) {
            @unlink($this->tempPath);
            $this->tempPath = null;
        }
    }

    public function kind(): SourceKind
    {
        return $this->kind;
    }

    public function version(): string
    {
        return $this->hasTable('stat_daily') ? '2.x' : '1.23';
    }

    public function instanceKey(): string
    {
        $secret = $this->setting('jwtSecret');
        if ($secret === null || $secret === '') {
            throw new UnreadableSource('The database has no jwtSecret, so the instance cannot be identified.');
        }

        return hash('sha256', 'kuma-db:'.(is_string($secret) ? $secret : json_encode($secret)));
    }

    public function monitors(): array
    {
        return $this->select('monitor', self::MONITOR_COLUMNS);
    }

    public function notifications(): array
    {
        $out = [];
        foreach ($this->select('notification', ['id', 'name', 'active', 'is_default', 'config']) as $row) {
            $config = is_string($row['config']) ? json_decode($row['config'], true) : null;
            $out[] = [
                'id' => Cast::int($row['id']),
                'name' => Cast::string($row['name']),
                'active' => (bool) $row['active'],
                'is_default' => (bool) $row['is_default'],
                'config' => Cast::map($config),
            ];
        }

        return $out;
    }

    public function monitorNotifications(): array
    {
        $out = [];
        foreach ($this->select('monitor_notification', ['monitor_id', 'notification_id']) as $row) {
            $out[] = ['monitor_id' => Cast::int($row['monitor_id']), 'notification_id' => Cast::int($row['notification_id'])];
        }

        return $out;
    }

    public function tags(): array
    {
        return $this->select('tag', ['id', 'name', 'color']);
    }

    public function monitorTags(): array
    {
        return $this->select('monitor_tag', ['monitor_id', 'tag_id', 'value']);
    }

    public function proxies(): array
    {
        return $this->select('proxy', ['id', 'protocol', 'host', 'port', 'auth', 'username', 'password', 'active', 'default']);
    }

    public function statusPages(): array
    {
        return $this->select('status_page', [
            'id', 'slug', 'title', 'description', 'icon', 'theme', 'published', 'search_engine_index',
            'show_tags', 'footer_text', 'custom_css', 'show_powered_by', 'show_certificate_expiry',
            'auto_refresh_interval', 'analytics_id', 'analytics_type', 'analytics_script_url',
            'google_analytics_tag_id', 'show_only_last_heartbeat', 'rss_title',
        ]);
    }

    public function statusPageGroups(): array
    {
        return $this->select('group', ['id', 'name', 'public', 'active', 'weight', 'status_page_id']);
    }

    public function groupMonitors(): array
    {
        return $this->select('monitor_group', ['id', 'monitor_id', 'group_id', 'weight', 'send_url', 'custom_url']);
    }

    public function statusPageDomains(): array
    {
        return $this->select('status_page_cname', ['id', 'status_page_id', 'domain']);
    }

    public function incidents(): array
    {
        return $this->select('incident', [
            'id', 'title', 'content', 'style', 'created_date', 'last_updated_date', 'pin', 'active', 'status_page_id',
        ]);
    }

    public function maintenances(): array
    {
        return $this->select('maintenance', [
            'id', 'title', 'description', 'active', 'strategy', 'start_date', 'end_date', 'start_time',
            'end_time', 'weekdays', 'days_of_month', 'interval_day', 'cron', 'timezone', 'duration',
        ]);
    }

    public function maintenanceMonitors(): array
    {
        return $this->select('monitor_maintenance', ['monitor_id', 'maintenance_id']);
    }

    public function maintenanceStatusPages(): array
    {
        return $this->select('maintenance_status_page', ['status_page_id', 'maintenance_id']);
    }

    public function setting(string $key): mixed
    {
        if (! $this->hasTable('setting')) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT '.$this->quote('value').' FROM '.$this->quote('setting').' WHERE '.$this->quote('key').' = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        $stmt->closeCursor();
        if (! is_string($value)) {
            return $value === false ? null : $value;
        }
        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    public function notMigratedCounts(): array
    {
        return [
            'users' => $this->count('user'),
            'api_keys' => $this->count('api_key'),
            'docker_hosts' => $this->count('docker_host'),
            'remote_browsers' => $this->count('remote_browser'),
        ];
    }

    public function historyKind(): string
    {
        return match (true) {
            $this->hasTable('stat_daily') => 'stats',
            $this->hasTable('heartbeat') => 'heartbeats',
            default => 'none',
        };
    }

    public function hourStats(): iterable
    {
        return $this->stats('stat_hourly');
    }

    public function dayStats(): iterable
    {
        return $this->stats('stat_daily');
    }

    public function heartbeats(int $monitorId): iterable
    {
        if (! $this->hasTable('heartbeat')) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'SELECT status, ping, '.$this->quote('time').' FROM heartbeat WHERE monitor_id = ? ORDER BY '.$this->quote('time'),
        );
        $stmt->execute([$monitorId]);
        try {
            $utc = new DateTimeZone('UTC');
            while (($row = $stmt->fetch()) !== false) {
                if (! is_array($row) || ! is_string($row['time'])) {
                    continue;
                }
                try {
                    $time = (new DateTimeImmutable($row['time'], $utc))->getTimestamp();
                } catch (Exception) {
                    continue;
                }
                yield [
                    'status' => Cast::int($row['status']),
                    'ping' => Cast::float($row['ping']),
                    'time' => $time,
                ];
            }
        } finally {
            $stmt->closeCursor();
        }
    }

    /**
     * @return Generator<int, array{monitor_id: int, timestamp: int, up: int, down: int, maintenance: int, ping: ?float, ping_min: ?float, ping_max: ?float}>
     */
    private function stats(string $table): Generator
    {
        if (! $this->hasTable($table)) {
            return;
        }
        $wanted = ['monitor_id', 'timestamp', 'up', 'down', 'ping', 'ping_min', 'ping_max', 'extras'];
        $present = array_values(array_intersect($wanted, $this->columns($table)));
        $stmt = $this->pdo->query(
            'SELECT '.implode(', ', array_map($this->quote(...), $present)).' FROM '.$this->quote($table)
            .' ORDER BY monitor_id, '.$this->quote('timestamp'),
        );
        if ($stmt === false) {
            return;
        }
        try {
            while (($row = $stmt->fetch()) !== false) {
                if (! is_array($row)) {
                    continue;
                }
                $extras = is_string($row['extras'] ?? null) ? json_decode($row['extras'], true) : null;
                yield [
                    'monitor_id' => Cast::int($row['monitor_id'] ?? null),
                    'timestamp' => Cast::int($row['timestamp'] ?? null),
                    'up' => Cast::int($row['up'] ?? null),
                    'down' => Cast::int($row['down'] ?? null),
                    'maintenance' => is_array($extras) ? Cast::int($extras['maintenance'] ?? 0) : 0,
                    'ping' => Cast::float($row['ping'] ?? null),
                    'ping_min' => Cast::float($row['ping_min'] ?? null),
                    'ping_max' => Cast::float($row['ping_max'] ?? null),
                ];
            }
        } finally {
            $stmt->closeCursor();
        }
    }

    /**
     * Reads the wanted columns that exist in the table; the others come back as null.
     *
     * @param  list<string>  $wanted
     * @return list<array<string, mixed>>
     */
    private function select(string $table, array $wanted, ?string $orderBy = null): array
    {
        if (! $this->hasTable($table)) {
            return [];
        }
        $present = array_values(array_intersect($wanted, $this->columns($table)));
        $order = $orderBy ?? (in_array('id', $present, true) ? $this->quote('id') : null);
        $sql = 'SELECT '.implode(', ', array_map($this->quote(...), $present)).' FROM '.$this->quote($table)
            .($order !== null ? ' ORDER BY '.$order : '');
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            return [];
        }

        $rows = [];
        while (($row = $stmt->fetch()) !== false) {
            if (! is_array($row)) {
                continue;
            }
            $out = [];
            foreach ($wanted as $column) {
                $out[$column] = $row[$column] ?? null;
            }
            $rows[] = $out;
        }

        return $rows;
    }

    private function count(string $table): int
    {
        if (! $this->hasTable($table)) {
            return 0;
        }

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM '.$this->quote($table));

        if ($stmt === false) {
            return 0;
        }
        $count = Cast::int($stmt->fetchColumn());
        $stmt->closeCursor();

        return $count;
    }

    private function hasTable(string $table): bool
    {
        return $this->tableCache[$table] ??= $this->lookupTable($table);
    }

    /**
     * @throws UnreadableSource when a SQLite table is virtual: its rows come from a module, not from the file
     *                          as Kuma writes it
     */
    private function lookupTable(string $table): bool
    {
        if ($this->kind !== SourceKind::Sqlite) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $stmt->execute([$table]);
            $found = Cast::int($stmt->fetchColumn()) > 0;
            $stmt->closeCursor();

            return $found;
        }

        $stmt = $this->pdo->prepare("SELECT sql, rootpage FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        $rows = $stmt->fetchAll();
        $stmt->closeCursor();
        foreach ($rows as $row) {
            $sql = is_array($row) && is_string($row['sql'] ?? null) ? $row['sql'] : '';
            // A virtual table has no b-tree of its own (root page 0).
            if (preg_match('/^\s*CREATE\s+VIRTUAL\b/i', $sql) === 1 || ! is_array($row) || Cast::int($row['rootpage'] ?? 0) === 0) {
                throw new UnreadableSource('The database contains a virtual table ('.$table.') where Uptime Kuma uses a regular table.');
            }
        }

        return $rows !== [];
    }

    /**
     * @return list<string>
     */
    private function columns(string $table): array
    {
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }
        $sql = $this->kind === SourceKind::Sqlite
            ? 'SELECT name AS c FROM pragma_table_info(?)'
            : 'SELECT column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$table]);

        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (is_string($name)) {
                $columns[] = $name;
            }
        }

        return $this->columnCache[$table] = $columns;
    }

    private function quote(string $identifier): string
    {
        return $this->kind === SourceKind::Sqlite ? '"'.$identifier.'"' : '`'.$identifier.'`';
    }
}
