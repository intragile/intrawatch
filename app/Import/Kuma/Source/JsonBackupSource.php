<?php

declare(strict_types=1);

namespace App\Import\Kuma\Source;

use JsonException;

/**
 * Reads the 1.23 "Export Backup" JSON, which carries monitors, notifications and tags only.
 */
final class JsonBackupSource implements KumaSource
{
    /** Columns that are booleans in the backup but 0/1 integers in a database. */
    private const BOOLEAN_COLUMNS = [
        'active', 'ignore_tls', 'upside_down', 'expiry_notification', 'invert_keyword',
        'domain_expiry_notification', 'ping_numeric', 'cache_bust',
    ];

    /**
     * @param  list<array<string, mixed>>  $monitorList
     * @param  list<array<string, mixed>>  $notificationList
     */
    private function __construct(
        private readonly array $monitorList,
        private readonly array $notificationList,
    ) {}

    /**
     * @throws UnreadableSource
     */
    public static function fromFile(string $path): self
    {
        $raw = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new UnreadableSource('The source file does not exist or cannot be read.');
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnreadableSource('The file is not valid JSON.');
        }
        if (! is_array($data) || ! isset($data['monitorList']) || ! is_array($data['monitorList'])) {
            throw new UnreadableSource('The JSON file is not an Uptime Kuma backup (no monitorList).');
        }

        return new self(
            self::rows($data['monitorList']),
            isset($data['notificationList']) && is_array($data['notificationList']) ? self::rows($data['notificationList']) : [],
        );
    }

    /**
     * @param  array<mixed>  $list
     * @return list<array<string, mixed>>
     */
    private static function rows(array $list): array
    {
        $rows = [];
        foreach ($list as $row) {
            if (is_array($row)) {
                $rows[] = Cast::map($row);
            }
        }

        return $rows;
    }

    public function kind(): SourceKind
    {
        return SourceKind::Json;
    }

    public function version(): string
    {
        return '1.23';
    }

    public function instanceKey(): string
    {
        return hash('sha256', 'kuma-json');
    }

    public function monitors(): array
    {
        $monitors = [];
        foreach ($this->monitorList as $entry) {
            $row = [];
            foreach ($entry as $key => $value) {
                $row[self::snake((string) $key)] = $value;
            }
            if (isset($row['accepted_statuscodes']) && is_array($row['accepted_statuscodes'])) {
                $row['accepted_statuscodes_json'] = json_encode($row['accepted_statuscodes'], JSON_THROW_ON_ERROR);
            }

            $out = [];
            foreach (self::MONITOR_COLUMNS as $column) {
                $value = $row[$column] ?? null;
                if (is_bool($value) || (in_array($column, self::BOOLEAN_COLUMNS, true) && $value !== null)) {
                    $value = (int) (bool) $value;
                }
                $out[$column] = $value;
            }
            $monitors[] = $out;
        }

        return $monitors;
    }

    public function notifications(): array
    {
        $notifications = [];
        foreach ($this->notificationList as $row) {
            $config = $row['config'] ?? [];
            if (is_string($config)) {
                $config = json_decode($config, true);
            }
            $notifications[] = [
                'id' => Cast::int($row['id'] ?? 0),
                'name' => Cast::string($row['name'] ?? ''),
                'active' => (bool) ($row['active'] ?? true),
                'is_default' => (bool) ($row['isDefault'] ?? $row['is_default'] ?? false),
                'config' => Cast::map($config),
            ];
        }

        return $notifications;
    }

    public function monitorNotifications(): array
    {
        $links = [];
        foreach ($this->monitorList as $monitor) {
            $ids = $monitor['notificationIDList'] ?? [];
            if (! is_array($ids)) {
                continue;
            }
            foreach ($ids as $notificationId => $enabled) {
                if ($enabled) {
                    $links[] = ['monitor_id' => Cast::int($monitor['id'] ?? 0), 'notification_id' => Cast::int($notificationId)];
                }
            }
        }

        return $links;
    }

    public function tags(): array
    {
        $tags = [];
        foreach ($this->monitorTagEntries() as $entry) {
            $tags[$entry['tag_id']] ??= ['id' => $entry['tag_id'], 'name' => $entry['name'], 'color' => $entry['color']];
        }
        ksort($tags);

        return array_values($tags);
    }

    public function monitorTags(): array
    {
        return array_map(
            static fn (array $e): array => ['monitor_id' => $e['monitor_id'], 'tag_id' => $e['tag_id'], 'value' => $e['value']],
            $this->monitorTagEntries(),
        );
    }

    /**
     * @return list<array{monitor_id: int, tag_id: int, name: string, color: mixed, value: mixed}>
     */
    private function monitorTagEntries(): array
    {
        $entries = [];
        foreach ($this->monitorList as $monitor) {
            $tags = $monitor['tags'] ?? [];
            if (! is_array($tags)) {
                continue;
            }
            foreach ($tags as $tag) {
                if (! is_array($tag)) {
                    continue;
                }
                $entries[] = [
                    'monitor_id' => Cast::int($monitor['id'] ?? 0),
                    'tag_id' => Cast::int($tag['tag_id'] ?? $tag['id'] ?? 0),
                    'name' => Cast::string($tag['name'] ?? ''),
                    'color' => $tag['color'] ?? null,
                    'value' => $tag['value'] ?? null,
                ];
            }
        }

        return $entries;
    }

    public function proxies(): array
    {
        return [];
    }

    public function statusPages(): array
    {
        return [];
    }

    public function statusPageGroups(): array
    {
        return [];
    }

    public function groupMonitors(): array
    {
        return [];
    }

    public function statusPageDomains(): array
    {
        return [];
    }

    public function incidents(): array
    {
        return [];
    }

    public function maintenances(): array
    {
        return [];
    }

    public function maintenanceMonitors(): array
    {
        return [];
    }

    public function maintenanceStatusPages(): array
    {
        return [];
    }

    public function setting(string $key): mixed
    {
        return null;
    }

    public function notMigratedCounts(): array
    {
        return ['users' => 0, 'api_keys' => 0, 'docker_hosts' => 0, 'remote_browsers' => 0];
    }

    public function historyKind(): string
    {
        return 'none';
    }

    public function hourStats(): iterable
    {
        return [];
    }

    public function dayStats(): iterable
    {
        return [];
    }

    public function heartbeats(int $monitorId): iterable
    {
        return [];
    }

    public function close(): void {}

    private static function snake(string $key): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
    }
}
