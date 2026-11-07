<?php

declare(strict_types=1);

namespace App\Import\Kuma\Source;

/**
 * Read-only view of one Uptime Kuma instance. Every row uses the Kuma snake_case column names; columns the
 * source version does not have are null, and tables it does not have yield empty lists.
 */
interface KumaSource
{
    /**
     * Monitor columns the importer reads. Anything else in the source is ignored.
     */
    public const MONITOR_COLUMNS = [
        'id', 'name', 'active', 'interval', 'url', 'type', 'weight', 'hostname', 'port', 'keyword',
        'maxretries', 'ignore_tls', 'upside_down', 'maxredirects', 'accepted_statuscodes_json',
        'dns_resolve_type', 'dns_resolve_server', 'retry_interval', 'push_token', 'method', 'body',
        'headers', 'basic_auth_user', 'basic_auth_pass', 'proxy_id', 'expiry_notification',
        'auth_method', 'auth_domain', 'auth_workstation', 'resend_interval', 'packet_size',
        'http_body_encoding', 'description', 'tls_ca', 'tls_cert', 'tls_key', 'parent', 'invert_keyword',
        'json_path', 'expected_value', 'oauth_client_id', 'oauth_client_secret', 'oauth_token_url',
        'oauth_scopes', 'oauth_auth_method', 'oauth_audience', 'timeout', 'json_path_operator',
        'conditions', 'ping_count', 'ping_numeric', 'ping_per_request_timeout', 'ip_family',
        'domain_expiry_notification', 'bearer_token', 'cache_bust',
    ];

    public function kind(): SourceKind;

    /**
     * @return '2.x'|'1.23'
     */
    public function version(): string;

    /**
     * Stable identifier of the Kuma instance, 64 hex characters.
     */
    public function instanceKey(): string;

    /**
     * @return list<array<string, mixed>>
     */
    public function monitors(): array;

    /**
     * @return list<array{id: int, name: string, active: bool, is_default: bool, config: array<string, mixed>}>
     */
    public function notifications(): array;

    /**
     * @return list<array{monitor_id: int, notification_id: int}>
     */
    public function monitorNotifications(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function tags(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function monitorTags(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function proxies(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function statusPages(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function statusPageGroups(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function groupMonitors(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function statusPageDomains(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function incidents(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function maintenances(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function maintenanceMonitors(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function maintenanceStatusPages(): array;

    /**
     * A `setting` value, JSON-decoded; a value that is not JSON is returned as the raw string.
     */
    public function setting(string $key): mixed;

    /**
     * @return array{users: int, api_keys: int, docker_hosts: int, remote_browsers: int}
     */
    public function notMigratedCounts(): array;

    /**
     * @return 'stats'|'heartbeats'|'none'
     */
    public function historyKind(): string;

    /**
     * @return iterable<array{monitor_id: int, timestamp: int, up: int, down: int, maintenance: int, ping: ?float, ping_min: ?float, ping_max: ?float}>
     */
    public function hourStats(): iterable;

    /**
     * @return iterable<array{monitor_id: int, timestamp: int, up: int, down: int, maintenance: int, ping: ?float, ping_min: ?float, ping_max: ?float}>
     */
    public function dayStats(): iterable;

    /**
     * @return iterable<array{status: int, ping: ?float, time: int}> epoch seconds, oldest first
     */
    public function heartbeats(int $monitorId): iterable;

    /**
     * Releases temporary resources; safe to call more than once.
     */
    public function close(): void;
}
