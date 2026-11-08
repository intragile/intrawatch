<?php

declare(strict_types=1);

namespace App\Import\Kuma\Mapping;

use App\Enums\MonitorType;
use App\Monitoring\Types\Definitions\HttpDefinition;
use App\Monitoring\Types\TypeDefinition;

/**
 * Translates a Kuma `monitor` row into a `POST /monitors` payload, so the API validation applies unchanged.
 *
 * Values outside IntraWatch's limits are clamped with a note. A setting IntraWatch cannot honour (an
 * unsupported auth method or proxy protocol, an untranslatable JSONata expression, conditions, …) marks the
 * result as paused: the monitor is created but inactive, so it never reports something Kuma would not have.
 */
final class MonitorMapper
{
    public const MAX_NAME = 120;

    public const MAX_DESCRIPTION = 5000;

    public const MAX_TAG_VALUE = 120;

    private const TYPES = [
        'http' => MonitorType::Http,
        'keyword' => MonitorType::Http,
        'json-query' => MonitorType::Http,
        'port' => MonitorType::Tcp,
        'ping' => MonitorType::Ping,
        'dns' => MonitorType::Dns,
        'push' => MonitorType::Push,
        'group' => MonitorType::Group,
    ];

    private const BODY_CONTENT_TYPES = [
        'json' => 'application/json',
        'form' => 'application/x-www-form-urlencoded',
        'xml' => 'text/xml',
    ];

    private const JSON_OPERATORS = ['==', '!=', '>', '>=', '<', '<=', 'contains'];

    private const MAX_PING_COUNT = 5;

    private const DNS_PORT = 53;

    /** @var list<Note> */
    private array $notes = [];

    private bool $pause = false;

    public function __construct(private readonly ProxyMapper $proxies = new ProxyMapper) {}

    /**
     * @param  array<string, mixed>  $row  A Kuma `monitor` row (snake_case columns).
     */
    public function map(array $row, MonitorMapContext $ctx): Mapped|Unmappable
    {
        $kumaType = self::str($row['type'] ?? null);
        $type = self::TYPES[$kumaType] ?? null;
        if ($type === null) {
            return new Unmappable(Note::of(
                'type_unsupported',
                'Monitors of type "'.($kumaType === '' ? 'unknown' : mb_substr($kumaType, 0, 40)).'" have no IntraWatch equivalent; the monitor was skipped.',
            ));
        }

        $this->notes = [];
        $this->pause = false;
        $definition = $type->definition();
        $sourceId = self::int($row['id'] ?? null);

        $attributes = ['name' => $this->name($row), 'type' => $type->value, 'description' => $this->description($row)];
        $attributes['active'] = self::bool($row['active'] ?? true);
        $attributes += $this->timing($row, $definition);
        $attributes['inverted'] = $this->inverted($row, $definition);

        $secrets = [];
        $config = match ($type) {
            MonitorType::Http => $this->http($row, $kumaType, $ctx, $secrets),
            MonitorType::Tcp => ['host' => trim(self::str($row['hostname'] ?? null)), 'port' => self::int($row['port'] ?? null)],
            MonitorType::Ping => ['host' => trim(self::str($row['hostname'] ?? null)), 'count' => $this->pingCount($row, self::int($attributes['timeout_seconds']))],
            MonitorType::Dns => $this->dns($row),
            MonitorType::Group => ['mode' => 'all'],
            default => [],
        };
        $this->conditions($row);

        $attributes['parent_id'] = $this->parent($row, $ctx);
        $attributes['config'] = $config;
        $attributes['secrets'] = $secrets;
        $attributes['tags'] = $this->tags($sourceId, $ctx);
        $attributes['notification_channel_ids'] = $this->channels($sourceId, $ctx);

        if ($this->pause) {
            $attributes['active'] = false;
        }

        return new Mapped($attributes, $this->notes, $this->pause);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function name(array $row): string
    {
        $name = trim(self::str($row['name'] ?? null));
        if (mb_strlen($name) > self::MAX_NAME) {
            $name = mb_substr($name, 0, self::MAX_NAME);
            $this->note('name_truncated', 'The name was longer than '.self::MAX_NAME.' characters and was shortened.');
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function description(array $row): ?string
    {
        $description = trim(self::str($row['description'] ?? null));
        if ($description === '') {
            return null;
        }
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            $description = mb_substr($description, 0, self::MAX_DESCRIPTION);
            $this->note('description_truncated', 'The description was longer than '.self::MAX_DESCRIPTION.' characters and was shortened.');
        }

        return $description;
    }

    /**
     * Interval, timeout, retries and the resend reminder, clamped to IntraWatch's limits.
     *
     * @param  array<string, mixed>  $row
     * @return array{interval_seconds: int, timeout_seconds: int, retries: int, retry_interval_seconds: int, alerting: array{resend_interval_minutes: int}}
     */
    private function timing(array $row, TypeDefinition $definition): array
    {
        $min = $definition->minIntervalSeconds();
        $max = config()->integer('monitoring.limits.interval.max');

        $sourceInterval = is_numeric($row['interval'] ?? null) ? (int) round((float) $row['interval']) : 0;
        $interval = $sourceInterval > 0 ? $sourceInterval : $definition->defaultIntervalSeconds();
        if ($interval < $min) {
            $interval = $min;
            $this->note('interval_raised', "The check interval was below IntraWatch's minimum and was raised to {$min} seconds.");
        } elseif ($interval > $max) {
            $interval = $max;
            $this->note('interval_lowered', "The check interval was above IntraWatch's maximum and was lowered to {$max} seconds.");
        }

        $sourceTimeout = is_numeric($row['timeout'] ?? null) && (float) $row['timeout'] > 0 ? (float) $row['timeout'] : null;
        $upper = min(
            config()->integer('monitoring.limits.timeout.max'),
            (int) floor(config()->float('monitoring.limits.timeout.max_interval_ratio') * $interval),
        );
        $timeout = $sourceTimeout === null ? config()->integer('monitoring.limits.timeout.default') : (int) round($sourceTimeout);
        $timeout = max(config()->integer('monitoring.limits.timeout.min'), min($upper, $timeout));
        if ($sourceTimeout !== null && (float) $timeout !== $sourceTimeout) {
            $this->note('timeout_adjusted', "The timeout did not fit IntraWatch's limits for this interval and was set to {$timeout} seconds.");
        }

        $retryMin = config()->integer('monitoring.limits.retry_interval_min');
        $sourceRetry = is_numeric($row['retry_interval'] ?? null) ? (int) round((float) $row['retry_interval']) : 0;
        $retryInterval = $sourceRetry > 0 ? $sourceRetry : $interval;
        if ($retryInterval < $retryMin) {
            $retryInterval = $retryMin;
            $this->note('retry_interval_raised', "The retry interval was below IntraWatch's minimum and was raised to {$retryMin} seconds.");
        } elseif ($retryInterval > $max) {
            $retryInterval = $max;
            $this->note('retry_interval_lowered', "The retry interval was above IntraWatch's maximum and was lowered to {$max} seconds.");
        }

        $retriesMax = config()->integer('monitoring.limits.retries.max');
        $retries = max(config()->integer('monitoring.limits.retries.min'), self::int($row['maxretries'] ?? null));
        if ($retries > $retriesMax) {
            $retries = $retriesMax;
            $this->note('retries_lowered', "The number of retries was above IntraWatch's maximum and was lowered to {$retriesMax}.");
        }

        return [
            'interval_seconds' => $interval,
            'timeout_seconds' => $timeout,
            'retries' => $retries,
            'retry_interval_seconds' => $retryInterval,
            'alerting' => ['resend_interval_minutes' => $this->resendMinutes($row, $sourceInterval > 0 ? $sourceInterval : $interval)],
        ];
    }

    /**
     * Kuma resends after a number of consecutive down beats; IntraWatch after a number of minutes. The beats
     * came at the source interval, so that is the wall-clock period being preserved.
     *
     * @param  array<string, mixed>  $row
     */
    private function resendMinutes(array $row, int $sourceInterval): int
    {
        $beats = max(0, self::int($row['resend_interval'] ?? null));
        if ($beats === 0) {
            return 0;
        }

        $minutes = (int) ceil($beats * $sourceInterval / 60);
        $max = 10080;
        if ($minutes > $max) {
            $this->note('resend_interval_lowered', 'The reminder interval was longer than a week and was lowered to one week.');

            return $max;
        }

        return $minutes;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function inverted(array $row, TypeDefinition $definition): bool
    {
        $inverted = self::bool($row['upside_down'] ?? false);
        if ($inverted && ! $definition->supportsInverted()) {
            $this->pauseWith('inverted_unsupported', 'The monitor is in upside-down mode, which this monitor type does not support in IntraWatch; it was imported paused and not inverted.');

            return false;
        }

        return $inverted;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $secrets
     * @return array<string, mixed>
     */
    private function http(array $row, string $kumaType, MonitorMapContext $ctx, array &$secrets): array
    {
        [$auth, $mtls] = $this->auth($row, $secrets);
        $url = $this->url(self::str($row['url'] ?? null), $auth, $secrets);
        $headers = $this->headers($row['headers'] ?? null, $secrets);

        $body = self::str($row['body'] ?? null);
        $body = $body === '' ? null : $body;
        $contentType = self::BODY_CONTENT_TYPES[strtolower(self::str($row['http_body_encoding'] ?? null))] ?? null;
        if ($body !== null && $contentType !== null && ! $this->hasHeader('content-type', $headers, $secrets)) {
            $headers['Content-Type'] = $contentType;
        }

        $method = strtoupper(trim(self::str($row['method'] ?? null)));
        $config = [
            'url' => $url,
            'method' => $method === '' ? 'GET' : $method,
            'headers' => $headers,
            'body' => $body,
            'accepted_status' => $this->acceptedStatus($row['accepted_statuscodes_json'] ?? null),
            'max_redirects' => $this->maxRedirects($row),
            'keyword' => null,
            'json_query' => null,
            'auth' => $auth,
            'mtls' => $mtls,
            'proxy' => $this->proxy($row, $ctx, $secrets),
            'tls_verify' => ! self::bool($row['ignore_tls'] ?? false),
            'tls_checks' => self::bool($row['expiry_notification'] ?? false),
            'domain_expiry' => self::bool($row['domain_expiry_notification'] ?? false),
        ];
        if ($config['tls_checks']) {
            $config['tls_expiry_days'] = $ctx->tlsExpiryDays;
        }

        if ($kumaType === 'keyword') {
            $config['keyword'] = [
                'value' => self::str($row['keyword'] ?? null),
                'mode' => self::bool($row['invert_keyword'] ?? false) ? 'not_contains' : 'contains',
                'case_sensitive' => true,
            ];
        }
        if ($kumaType === 'json-query') {
            $config['json_query'] = $this->jsonQuery($row);
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $secrets
     * @return array{0: array{type: string, username: string|null}, 1: bool}
     */
    private function auth(array $row, array &$secrets): array
    {
        $none = ['type' => 'none', 'username' => null];
        $method = strtolower(trim(self::str($row['auth_method'] ?? null)));
        $user = self::str($row['basic_auth_user'] ?? null);
        $pass = self::str($row['basic_auth_pass'] ?? null);

        switch ($method) {
            case '':
            case 'basic':
                if ($user === '' && $pass === '') {
                    return [$none, false];
                }
                if ($pass !== '') {
                    $secrets['basic_password'] = $pass;
                }

                return [['type' => 'basic', 'username' => $user === '' ? null : $user], false];
            case 'bearer':
                $token = self::str($row['bearer_token'] ?? null);
                if ($token !== '') {
                    $secrets['bearer_token'] = $token;
                }

                return [['type' => 'bearer', 'username' => null], false];
            case 'mtls':
                foreach (['tls_cert' => 'mtls_cert', 'tls_key' => 'mtls_key'] as $column => $secret) {
                    $value = self::str($row[$column] ?? null);
                    if ($value !== '') {
                        $secrets[$secret] = $value;
                    }
                }
                if (trim(self::str($row['tls_ca'] ?? null)) !== '') {
                    $this->note('mtls_ca_ignored', 'The custom CA certificate of the mTLS settings was not imported; the server certificate is checked against the system trust store.');
                }

                return [$none, true];
            default:
                $this->pauseWith('auth_unsupported', 'The monitor uses '.mb_substr($method, 0, 20).' authentication, which IntraWatch does not support; it was imported paused without authentication.');

                return [$none, false];
        }
    }

    /**
     * Strips credentials from the URL. They become basic authentication unless the monitor already
     * authenticates another way.
     *
     * @param  array{type: string, username: string|null}  $auth
     * @param  array<string, mixed>  $secrets
     */
    private function url(string $url, array &$auth, array &$secrets): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (! is_array($parts) || (! isset($parts['user']) && ! isset($parts['pass']))) {
            return $url;
        }

        $stripped = (string) preg_replace('#^([a-z][a-z0-9+.\-]*://)[^/?\#]*@#i', '$1', $url);
        if ($auth['type'] !== 'none') {
            $this->note('url_credentials_dropped', 'The URL contained credentials while the monitor already uses its own authentication; the URL credentials were removed.');

            return $stripped;
        }

        $user = rawurldecode($parts['user'] ?? '');
        $pass = rawurldecode($parts['pass'] ?? '');
        $auth = ['type' => 'basic', 'username' => $user === '' ? null : $user];
        if ($pass !== '') {
            $secrets['basic_password'] = $pass;
        }
        $this->note('url_credentials_moved', 'The URL contained credentials; they were moved to basic authentication.');

        return $stripped;
    }

    /**
     * Plain headers, with credential-carrying ones moved to the secret headers.
     *
     * @param  array<string, mixed>  $secrets
     * @return array<string, string>
     */
    private function headers(mixed $raw, array &$secrets): array
    {
        $raw = is_string($raw) ? trim($raw) : $raw;
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            $this->note('headers_ignored', 'The custom headers were not a JSON object and were not imported.');

            return [];
        }

        $headers = [];
        $secretHeaders = [];
        $dropped = false;
        foreach ($decoded as $name => $value) {
            if (! is_scalar($value)) {
                $dropped = true;

                continue;
            }
            $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            if (in_array(strtolower((string) $name), HttpDefinition::CREDENTIAL_HEADERS, true)) {
                $secretHeaders[(string) $name] = $value;
            } else {
                $headers[(string) $name] = $value;
            }
        }
        if ($dropped) {
            $this->note('header_dropped', 'Custom headers whose value is not text were not imported.');
        }
        if ($secretHeaders !== []) {
            $secrets['secret_headers'] = $secretHeaders;
        }

        return $headers;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $secrets
     */
    private function hasHeader(string $name, array $headers, array $secrets): bool
    {
        $secretHeaders = is_array($secrets['secret_headers'] ?? null) ? $secrets['secret_headers'] : [];
        foreach ([...array_keys($headers), ...array_keys($secretHeaders)] as $key) {
            if (strtolower((string) $key) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function acceptedStatus(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        $statuses = [];
        if (is_array($decoded)) {
            foreach ($decoded as $status) {
                if (is_scalar($status) && trim((string) $status) !== '') {
                    $statuses[] = trim((string) $status);
                }
            }
        }

        return $statuses === [] ? ['200-299'] : $statuses;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function maxRedirects(array $row): int
    {
        $max = config()->integer('monitoring.limits.http.max_redirects');
        $redirects = is_numeric($row['maxredirects'] ?? null)
            ? max(0, (int) $row['maxredirects'])
            : config()->integer('monitoring.limits.http.default_redirects');
        if ($redirects > $max) {
            $this->note('max_redirects_lowered', "The redirect limit was above IntraWatch's maximum and was lowered to {$max}.");

            return $max;
        }

        return $redirects;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $secrets
     * @return array{url: string, username: string|null}|null
     */
    private function proxy(array $row, MonitorMapContext $ctx, array &$secrets): ?array
    {
        $id = self::int($row['proxy_id'] ?? null);
        if ($id <= 0) {
            return null;
        }

        $proxy = $ctx->proxy($id);
        if ($proxy === null) {
            $this->pauseWith('proxy_missing', 'The monitor uses a proxy that is not part of the import; it was imported paused without the proxy.');

            return null;
        }

        $mapped = $this->proxies->map($proxy);
        if ($mapped instanceof Note) {
            $this->notes[] = $mapped;
            $this->pause = true;

            return null;
        }
        if ($mapped['password'] !== null) {
            $secrets['proxy_password'] = $mapped['password'];
        }

        return $mapped['proxy'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{path: string, operator: string, expected: string|null}|null
     */
    private function jsonQuery(array $row): ?array
    {
        $path = JsonataPath::toJsonPath(self::str($row['json_path'] ?? null));
        if ($path === null) {
            $this->pauseWith('jsonata_untranslatable', 'The JSON query is a JSONata expression that has no JSONPath equivalent; the monitor was imported paused without the JSON query.');

            return null;
        }
        $this->note('jsonata_translated', 'The JSONata path was translated to JSONPath. JSONata steps into arrays implicitly and JSONPath does not; verify the expression against the response.');

        $operator = trim(self::str($row['json_path_operator'] ?? null));
        $operator = $operator === '' ? '==' : $operator;
        if (! in_array($operator, self::JSON_OPERATORS, true)) {
            $this->pauseWith('json_operator_unsupported', 'The JSON query uses an operator IntraWatch does not support; the monitor was imported paused with an equality check.');
            $operator = '==';
        }

        $expected = $row['expected_value'] ?? null;

        return ['path' => $path, 'operator' => $operator, 'expected' => is_scalar($expected) ? (string) $expected : null];
    }

    /**
     * Ping sends its packets one second apart and IntraWatch requires the last to leave before the timeout.
     *
     * @param  array<string, mixed>  $row
     */
    private function pingCount(array $row, int $timeout): int
    {
        $source = is_numeric($row['ping_count'] ?? null) ? (int) $row['ping_count'] : 1;
        $count = max(1, min(self::MAX_PING_COUNT, $source, $timeout));
        if ($count < $source) {
            $this->note('ping_count_lowered', "The ping count was lowered to {$count} to fit IntraWatch's limit and the timeout.");
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{name: string, record_type: string, resolver: string|null, expected: list<string>, match: string}
     */
    private function dns(array $row): array
    {
        $servers = array_values(array_filter(array_map('trim', explode(',', self::str($row['dns_resolve_server'] ?? null))), fn (string $s): bool => $s !== ''));
        if (count($servers) > 1) {
            $this->note('dns_resolver_reduced', 'IntraWatch queries one resolver; only the first configured resolver was imported.');
        }
        $resolver = $servers[0] ?? null;
        $port = self::int($row['port'] ?? null);
        if ($resolver !== null && $port > 0 && $port !== self::DNS_PORT) {
            $resolver = filter_var($resolver, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? "[{$resolver}]:{$port}" : "{$resolver}:{$port}";
        }
        $recordType = strtoupper(trim(self::str($row['dns_resolve_type'] ?? null)));

        return [
            'name' => trim(self::str($row['hostname'] ?? null)),
            'record_type' => $recordType === '' ? 'A' : $recordType,
            'resolver' => $resolver,
            'expected' => [],
            'match' => 'any',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function conditions(array $row): void
    {
        $raw = $row['conditions'] ?? null;
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if ((is_array($decoded) && $decoded !== []) || (is_string($raw) && trim($raw) !== '' && $decoded === null)) {
            $this->pauseWith('conditions_ignored', 'The monitor has conditions, which IntraWatch does not evaluate; it was imported paused without them.');
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function parent(array $row, MonitorMapContext $ctx): ?int
    {
        $parent = self::int($row['parent'] ?? null);
        if ($parent <= 0) {
            return null;
        }

        [$id, $note] = $ctx->parent($parent);
        if ($note !== null) {
            $this->notes[] = $note;
        }

        return $id;
    }

    /**
     * @return list<array{id: int, value: string|null}>
     */
    private function tags(int $monitorId, MonitorMapContext $ctx): array
    {
        $tags = [];
        $seen = [];
        foreach ($ctx->tagsOf($monitorId) as $link) {
            $kumaTagId = self::int($link['tag_id'] ?? null);
            if (isset($seen[$kumaTagId])) {
                $this->note('tag_value_dropped', 'The monitor carries the same tag more than once; IntraWatch keeps one value per tag, so the first one was kept.');

                continue;
            }
            $seen[$kumaTagId] = true;

            $tagId = $ctx->tagId($kumaTagId);
            if ($tagId === null) {
                $this->note('tag_not_imported', 'A tag of the monitor was not imported, so it was not assigned.');

                continue;
            }

            $value = trim(self::str($link['value'] ?? null));
            if (mb_strlen($value) > self::MAX_TAG_VALUE) {
                $value = mb_substr($value, 0, self::MAX_TAG_VALUE);
                $this->note('tag_value_truncated', 'A tag value was longer than '.self::MAX_TAG_VALUE.' characters and was shortened.');
            }
            $tags[] = ['id' => $tagId, 'value' => $value === '' ? null : $value];
        }

        return $tags;
    }

    /**
     * @return list<int>
     */
    private function channels(int $monitorId, MonitorMapContext $ctx): array
    {
        $ids = [];
        foreach ($ctx->notificationsOf($monitorId) as $notificationId) {
            $channelId = $ctx->channelId($notificationId);
            if ($channelId === null) {
                $this->note('channel_not_imported', 'A notification of the monitor was not imported as a channel, so it was not bound.');

                continue;
            }
            $ids[$channelId] = $channelId;
        }

        return array_values($ids);
    }

    private function note(string $code, string $message): void
    {
        $this->notes[] = Note::of($code, $message);
    }

    private function pauseWith(string $code, string $message): void
    {
        $this->note($code, $message);
        $this->pause = true;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function bool(mixed $value): bool
    {
        return is_string($value) ? ! in_array(strtolower($value), ['', '0', 'false'], true) : (bool) $value;
    }
}
