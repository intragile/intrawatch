<?php

declare(strict_types=1);

namespace Tests\Unit\Import\Kuma;

use App\Import\Kuma\Mapping\Mapped;
use App\Import\Kuma\Mapping\MonitorMapContext;
use App\Import\Kuma\Mapping\MonitorMapper;
use App\Import\Kuma\Mapping\Note;
use App\Import\Kuma\Mapping\Unmappable;
use App\Import\Kuma\Source\KumaSource;
use App\Import\Kuma\Source\SourceFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Import\Kuma\ImporterTest;
use Tests\TestCase;

final class MonitorMapperTest extends TestCase
{
    private MonitorMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new MonitorMapper;
    }

    /**
     * A Kuma `monitor` row as 2.x stores a plain HTTP monitor.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function row(array $overrides = []): array
    {
        $row = array_fill_keys(KumaSource::MONITOR_COLUMNS, null);

        return array_replace($row, [
            'id' => 5, 'name' => 'Site', 'active' => 1, 'interval' => 60, 'timeout' => 48.0, 'type' => 'http',
            'url' => 'https://example.com', 'method' => 'GET', 'maxretries' => 0, 'retry_interval' => 60,
            'resend_interval' => 0, 'upside_down' => 0, 'ignore_tls' => 0, 'maxredirects' => 10,
            'accepted_statuscodes_json' => '["200-299"]', 'http_body_encoding' => 'json', 'expiry_notification' => 0,
            'conditions' => '[]',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function mapped(array $overrides = [], ?MonitorMapContext $ctx = null): Mapped
    {
        $result = $this->mapper->map(self::row($overrides), $ctx ?? new MonitorMapContext);
        $this->assertInstanceOf(Mapped::class, $result);

        return $result;
    }

    /**
     * @return list<string>
     */
    private static function codes(Mapped|Unmappable $result): array
    {
        $notes = $result instanceof Mapped ? $result->notes : [$result->reason];

        return array_map(fn (Note $n): string => $n->code, $notes);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function config(Mapped $mapped): array
    {
        $config = $mapped->attributes['config'] ?? null;

        return is_array($config) ? $config : [];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function secrets(Mapped $mapped): array
    {
        $secrets = $mapped->attributes['secrets'] ?? null;

        return is_array($secrets) ? $secrets : [];
    }

    public function test_every_fixture_monitor_maps_as_listed(): void
    {
        $source = SourceFactory::open(ImporterTest::fixture('kuma2.db'));
        try {
            $proxies = [];
            foreach ($source->proxies() as $proxy) {
                $proxies[is_numeric($proxy['id']) ? (int) $proxy['id'] : 0] = $proxy;
            }
            $tags = [];
            foreach ($source->monitorTags() as $link) {
                $tags[is_numeric($link['monitor_id']) ? (int) $link['monitor_id'] : 0][] = $link;
            }
            $channels = [];
            foreach ($source->monitorNotifications() as $link) {
                $channels[$link['monitor_id']][] = $link['notification_id'];
            }
            $ctx = new MonitorMapContext(proxies: $proxies, monitorTags: $tags, monitorNotifications: $channels);

            $results = [];
            foreach ($source->monitors() as $row) {
                $results[is_numeric($row['id']) ? (int) $row['id'] : 0] = $this->mapper->map($row, $ctx);
            }
        } finally {
            $source->close();
        }

        $this->assertCount(10, $results);
        $types = [];
        foreach ($results as $id => $result) {
            $types[$id] = $result instanceof Mapped ? $result->attributes['type'] : 'skipped';
        }
        $this->assertSame([1 => 'group', 2 => 'http', 3 => 'http', 4 => 'http', 5 => 'http', 6 => 'tcp', 7 => 'ping', 8 => 'dns', 9 => 'push', 10 => 'skipped'], $types);

        $group = $results[1];
        $this->assertInstanceOf(Mapped::class, $group);
        $this->assertSame(['mode' => 'all'], self::config($group));
        $this->assertSame('group monitor', $group->attributes['description']);

        $http = $results[2];
        $this->assertInstanceOf(Mapped::class, $http);
        $this->assertSame(1, $http->attributes['parent_id']);
        $this->assertSame(3, $http->attributes['retries']);
        $this->assertSame(30, $http->attributes['retry_interval_seconds']);
        $this->assertSame(['resend_interval_minutes' => 10], $http->attributes['alerting']);
        $this->assertSame(['type' => 'basic', 'username' => 'bu'], self::config($http)['auth']);
        $this->assertSame('bp', self::secrets($http)['basic_password']);
        $this->assertSame(['200-299', '301'], self::config($http)['accepted_status']);
        $this->assertSame(['X-A' => '1'], self::config($http)['headers']);
        $this->assertTrue(self::config($http)['tls_checks']);
        $this->assertSame([7, 14, 21], self::config($http)['tls_expiry_days']);
        $this->assertFalse(self::config($http)['domain_expiry']);
        $this->assertSame([['id' => 1, 'value' => 'eu-west']], $http->attributes['tags']);
        $this->assertSame([1, 2], $http->attributes['notification_channel_ids']);
        $this->assertFalse($http->pause);

        $keyword = $results[3];
        $this->assertInstanceOf(Mapped::class, $keyword);
        $this->assertSame(['value' => 'Example Domain', 'mode' => 'contains', 'case_sensitive' => true], self::config($keyword)['keyword']);

        $json = $results[4];
        $this->assertInstanceOf(Mapped::class, $json);
        $this->assertSame(['path' => '$.slideshow.author', 'operator' => '==', 'expected' => 'Yours Truly'], self::config($json)['json_query']);
        $this->assertFalse($json->pause);
        $this->assertSame(['jsonata_translated'], self::codes($json));

        $proxied = $results[5];
        $this->assertInstanceOf(Mapped::class, $proxied);
        $this->assertFalse($proxied->attributes['active']);
        $this->assertFalse($proxied->pause, 'inactive in Kuma, not paused by the mapping');
        $this->assertSame(['url' => 'http://proxy.example.com:3128', 'username' => 'pu'], self::config($proxied)['proxy']);
        $this->assertSame('pp', self::secrets($proxied)['proxy_password']);
        $this->assertSame('POST', self::config($proxied)['method']);
        $this->assertSame('{"a":1}', self::config($proxied)['body']);
        $this->assertSame(['Content-Type' => 'application/json'], self::config($proxied)['headers']);

        $tcp = $results[6];
        $this->assertInstanceOf(Mapped::class, $tcp);
        $this->assertSame(['host' => 'example.com', 'port' => 443], self::config($tcp));

        $ping = $results[7];
        $this->assertInstanceOf(Mapped::class, $ping);
        $this->assertSame(['host' => '127.0.0.1', 'count' => 1], self::config($ping));
        $this->assertSame([['id' => 1, 'value' => null]], $ping->attributes['tags']);

        $dns = $results[8];
        $this->assertInstanceOf(Mapped::class, $dns);
        $this->assertSame(['name' => 'example.com', 'record_type' => 'A', 'resolver' => '1.1.1.1', 'expected' => [], 'match' => 'any'], self::config($dns));

        $push = $results[9];
        $this->assertInstanceOf(Mapped::class, $push);
        $this->assertSame([], self::config($push));
        $this->assertSame(60, $push->attributes['interval_seconds']);

        $this->assertSame(['type_unsupported'], self::codes($results[10]));
        $this->assertInstanceOf(Unmappable::class, $results[10]);
        $this->assertSame([], $results[10]->errors);
        $this->assertStringContainsString('redis', $results[10]->reason->message);
    }

    public function test_common_fields_map_to_api_keys(): void
    {
        $mapped = $this->mapped(['name' => ' Shop ', 'description' => '', 'upside_down' => 1, 'maxretries' => 2, 'active' => 0]);

        $this->assertSame('Shop', $mapped->attributes['name']);
        $this->assertNull($mapped->attributes['description']);
        $this->assertTrue($mapped->attributes['inverted']);
        $this->assertSame(2, $mapped->attributes['retries']);
        $this->assertFalse($mapped->attributes['active']);
        $this->assertSame(60, $mapped->attributes['interval_seconds']);
        $this->assertSame(48, $mapped->attributes['timeout_seconds']);
        $this->assertNull($mapped->attributes['parent_id']);
        $this->assertSame([], $mapped->attributes['tags']);
        $this->assertSame([], $mapped->attributes['notification_channel_ids']);
        $this->assertSame([], $mapped->notes);
    }

    public function test_interval_timeout_and_retries_are_clamped_with_notes(): void
    {
        $mapped = $this->mapped(['interval' => 10, 'timeout' => 30, 'retry_interval' => 5, 'maxretries' => 50]);

        $this->assertSame(20, $mapped->attributes['interval_seconds']);
        $this->assertSame(16, $mapped->attributes['timeout_seconds']);
        $this->assertSame(20, $mapped->attributes['retry_interval_seconds']);
        $this->assertSame(10, $mapped->attributes['retries']);
        $this->assertSame(['interval_raised', 'timeout_adjusted', 'retry_interval_raised', 'retries_lowered'], self::codes($mapped));

        $default = $this->mapped(['timeout' => null, 'interval' => 300]);
        $this->assertSame(10, $default->attributes['timeout_seconds']);
        $this->assertSame([], $default->notes);

        $long = $this->mapped(['timeout' => 600, 'interval' => 3600]);
        $this->assertSame(120, $long->attributes['timeout_seconds']);
        $this->assertSame(['timeout_adjusted'], self::codes($long));
    }

    public function test_resend_interval_counts_beats_of_the_source_interval(): void
    {
        $this->assertSame(['resend_interval_minutes' => 0], $this->mapped(['resend_interval' => 0])->attributes['alerting']);
        $this->assertSame(['resend_interval_minutes' => 3], $this->mapped(['resend_interval' => 5, 'interval' => 30])->attributes['alerting']);
        $this->assertSame(['resend_interval_minutes' => 1], $this->mapped(['resend_interval' => 1, 'interval' => 20])->attributes['alerting']);
        $this->assertSame(['resend_interval_minutes' => 120], $this->mapped(['resend_interval' => 12, 'interval' => 600])->attributes['alerting']);
    }

    public function test_name_is_truncated_with_note(): void
    {
        $mapped = $this->mapped(['name' => str_repeat('n', 300)]);

        $this->assertSame(str_repeat('n', 120), $mapped->attributes['name']);
        $this->assertSame(['name_truncated'], self::codes($mapped));
    }

    public function test_keyword_invert_becomes_not_contains(): void
    {
        $mapped = $this->mapped(['type' => 'keyword', 'keyword' => 'Error', 'invert_keyword' => 1]);

        $this->assertSame(['value' => 'Error', 'mode' => 'not_contains', 'case_sensitive' => true], self::config($mapped)['keyword']);
    }

    public function test_json_query_translates_plain_jsonata(): void
    {
        $mapped = $this->mapped(['type' => 'json-query', 'json_path' => 'data.items[0].name', 'json_path_operator' => 'contains', 'expected_value' => 'x']);

        $this->assertSame(['path' => '$.data.items[0].name', 'operator' => 'contains', 'expected' => 'x'], self::config($mapped)['json_query']);
        $this->assertFalse($mapped->pause);

        $legacy = $this->mapped(['type' => 'json-query', 'json_path' => 'ok', 'json_path_operator' => null, 'expected_value' => 'true']);
        $this->assertSame(['path' => '$.ok', 'operator' => '==', 'expected' => 'true'], self::config($legacy)['json_query']);
    }

    public function test_translated_jsonata_is_noted_and_stays_active(): void
    {
        $mapped = $this->mapped(['type' => 'json-query', 'json_path' => 'data.items[0].name', 'expected_value' => 'x']);

        $this->assertSame(['jsonata_translated'], self::codes($mapped));
        $this->assertFalse($mapped->pause);
        $this->assertTrue($mapped->attributes['active']);
        $this->assertSame(['path' => '$.data.items[0].name', 'operator' => '==', 'expected' => 'x'], self::config($mapped)['json_query']);
    }

    public function test_untranslatable_jsonata_pauses(): void
    {
        $mapped = $this->mapped(['type' => 'json-query', 'json_path' => '$sum(x)', 'expected_value' => '3']);

        $this->assertTrue($mapped->pause);
        $this->assertFalse($mapped->attributes['active']);
        $this->assertNull(self::config($mapped)['json_query']);
        $this->assertSame(['jsonata_untranslatable'], self::codes($mapped));
    }

    public function test_bearer_auth_moves_the_token_to_secrets(): void
    {
        $mapped = $this->mapped(['auth_method' => 'bearer', 'bearer_token' => 'tok']);

        $this->assertSame(['type' => 'bearer', 'username' => null], self::config($mapped)['auth']);
        $this->assertSame(['bearer_token' => 'tok'], self::secrets($mapped));
    }

    public function test_mtls_keeps_cert_and_key_and_notes_the_ca(): void
    {
        $mapped = $this->mapped(['auth_method' => 'mtls', 'tls_cert' => 'CERT', 'tls_key' => 'KEY', 'tls_ca' => 'CA']);

        $this->assertTrue(self::config($mapped)['mtls']);
        $this->assertSame(['mtls_cert' => 'CERT', 'mtls_key' => 'KEY'], self::secrets($mapped));
        $this->assertSame(['mtls_ca_ignored'], self::codes($mapped));
        $this->assertFalse($mapped->pause);
    }

    public function test_ntlm_and_oauth_are_paused(): void
    {
        foreach (['ntlm', 'oauth2-cc'] as $method) {
            $mapped = $this->mapped(['auth_method' => $method, 'basic_auth_user' => 'u', 'basic_auth_pass' => 'p', 'oauth_client_secret' => 's']);

            $this->assertTrue($mapped->pause, $method);
            $this->assertFalse($mapped->attributes['active']);
            $this->assertSame(['type' => 'none', 'username' => null], self::config($mapped)['auth']);
            $this->assertSame([], self::secrets($mapped));
            $this->assertSame(['auth_unsupported'], self::codes($mapped));
        }
    }

    public function test_proxy_socks5_is_kept_and_https_pauses(): void
    {
        $proxies = [
            3 => ['id' => 3, 'protocol' => 'socks5', 'host' => '10.0.0.5', 'port' => 1080, 'auth' => 0, 'username' => null, 'password' => null],
            4 => ['id' => 4, 'protocol' => 'https', 'host' => 'p.example.com', 'port' => 443, 'auth' => 1, 'username' => 'u', 'password' => 'secret'],
            5 => ['id' => 5, 'protocol' => 'http', 'host' => '::1', 'port' => 8080, 'auth' => 1, 'username' => 'u', 'password' => 'pw'],
        ];
        $ctx = new MonitorMapContext(proxies: $proxies);

        $socks = $this->mapped(['proxy_id' => 3], $ctx);
        $this->assertSame(['url' => 'socks5://10.0.0.5:1080', 'username' => null], self::config($socks)['proxy']);
        $this->assertSame([], self::secrets($socks));
        $this->assertFalse($socks->pause);

        $https = $this->mapped(['proxy_id' => 4], $ctx);
        $this->assertTrue($https->pause);
        $this->assertNull(self::config($https)['proxy']);
        $this->assertSame([], self::secrets($https));
        $this->assertSame(['proxy_protocol_unsupported'], self::codes($https));

        $v6 = $this->mapped(['proxy_id' => 5], $ctx);
        $this->assertSame(['url' => 'http://[::1]:8080', 'username' => 'u'], self::config($v6)['proxy']);
        $this->assertSame(['proxy_password' => 'pw'], self::secrets($v6));

        $missing = $this->mapped(['proxy_id' => 9], $ctx);
        $this->assertTrue($missing->pause);
        $this->assertSame(['proxy_missing'], self::codes($missing));
    }

    public function test_url_credentials_move_to_basic_auth(): void
    {
        $mapped = $this->mapped(['url' => 'https://al%40ice:p%3Ass@example.com:8443/x?y=1']);

        $this->assertSame('https://example.com:8443/x?y=1', self::config($mapped)['url']);
        $this->assertSame(['type' => 'basic', 'username' => 'al@ice'], self::config($mapped)['auth']);
        $this->assertSame(['basic_password' => 'p:ss'], self::secrets($mapped));
        $this->assertSame(['url_credentials_moved'], self::codes($mapped));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function unparsableCredentialUrls(): array
    {
        return [
            'slash in password' => ['https://user:pa/ss@example.com/x', 'https://example.com/x', 'pa/ss'],
            'question mark in password' => ['https://user:pa?ss@example.com/x', 'https://example.com/x', 'pa?ss'],
            'hash in password' => ['https://user:pa#ss@example.com/x', 'https://example.com/x', 'pa#ss'],
            'extra slashes' => ['https:////user:pw0rd@example.com/x', 'https:////example.com/x', 'pw0rd'],
        ];
    }

    #[DataProvider('unparsableCredentialUrls')]
    public function test_credentials_are_stripped_from_urls_that_cannot_be_parsed(string $url, string $expected, string $password): void
    {
        $mapped = $this->mapped(['url' => $url]);

        $this->assertSame($expected, self::config($mapped)['url']);
        $this->assertContains('url_credentials_dropped', self::codes($mapped));
        $this->assertStringNotContainsString($password, (string) json_encode([$mapped->attributes, $mapped->notes]));
    }

    public function test_credentials_of_a_scheme_relative_url_are_stripped(): void
    {
        $mapped = $this->mapped(['url' => '//user:pw0rd@example.com/x']);

        $this->assertSame('//example.com/x', self::config($mapped)['url']);
        $this->assertSame(['type' => 'basic', 'username' => 'user'], self::config($mapped)['auth']);
        $this->assertSame(['basic_password' => 'pw0rd'], self::secrets($mapped));
        $this->assertStringNotContainsString('pw0rd', (string) json_encode(self::config($mapped)));
    }

    public function test_credential_stripping_cuts_at_the_last_at_sign_before_the_host(): void
    {
        $atInPassword = $this->mapped(['url' => 'https://u:p@x@example.com/']);
        $this->assertSame('https://example.com/', self::config($atInPassword)['url']);
        $this->assertSame(['basic_password' => 'p@x'], self::secrets($atInPassword));

        $atInQuery = $this->mapped(['url' => 'https://example.com/?to=a@b.com']);
        $this->assertSame('https://example.com/?to=a@b.com', self::config($atInQuery)['url']);
        $this->assertSame([], self::codes($atInQuery));

        $both = $this->mapped(['url' => 'https://u:p@example.com/?to=a@b.com']);
        $this->assertSame('https://example.com/?to=a@b.com', self::config($both)['url']);
        $this->assertSame(['basic_password' => 'p'], self::secrets($both));

        // An `@` followed by the host in the path or query must not become the cut point.
        $hostInQuery = $this->mapped(['url' => 'https://u:p@example.com/check?from=ops@example.com']);
        $this->assertSame('https://example.com/check?from=ops@example.com', self::config($hostInQuery)['url']);
        $this->assertSame(['basic_password' => 'p'], self::secrets($hostInQuery));

        $hostInPath = $this->mapped(['url' => 'https://u:p@example.com/users/x@example.com/profile']);
        $this->assertSame('https://example.com/users/x@example.com/profile', self::config($hostInPath)['url']);

        $hostInUser = $this->mapped(['url' => 'https://u@example.com:p@example.com/']);
        $this->assertSame('https://example.com/', self::config($hostInUser)['url']);
        $this->assertSame(['basic_password' => 'p'], self::secrets($hostInUser));
    }

    public function test_body_encoding_adds_a_content_type_when_none_is_set(): void
    {
        $form = $this->mapped(['method' => 'POST', 'body' => 'a=1', 'http_body_encoding' => 'form']);
        $this->assertSame(['Content-Type' => 'application/x-www-form-urlencoded'], self::config($form)['headers']);

        $xml = $this->mapped(['method' => 'POST', 'body' => '<a/>', 'http_body_encoding' => 'xml']);
        $this->assertSame(['Content-Type' => 'text/xml'], self::config($xml)['headers']);

        $own = $this->mapped(['method' => 'POST', 'body' => '{}', 'headers' => '{"content-type":"application/vnd.x+json"}']);
        $this->assertSame(['content-type' => 'application/vnd.x+json'], self::config($own)['headers']);

        $empty = $this->mapped(['body' => '', 'http_body_encoding' => 'json']);
        $this->assertSame([], self::config($empty)['headers']);
        $this->assertNull(self::config($empty)['body']);
    }

    public function test_credential_headers_move_to_secret_headers(): void
    {
        $mapped = $this->mapped(['headers' => '{"Authorization":"Bearer x","X-Trace":1,"Cookie":"c=1"}']);

        $this->assertSame(['X-Trace' => '1'], self::config($mapped)['headers']);
        $this->assertSame(['secret_headers' => ['Authorization' => 'Bearer x', 'Cookie' => 'c=1']], self::secrets($mapped));
    }

    public function test_header_json_that_is_not_an_object_is_dropped_with_note(): void
    {
        $mapped = $this->mapped(['headers' => '["a"]']);
        $this->assertSame([], self::config($mapped)['headers']);
        $this->assertSame(['headers_ignored'], self::codes($mapped));

        $broken = $this->mapped(['headers' => '{not json']);
        $this->assertSame(['headers_ignored'], self::codes($broken));
    }

    public function test_conditions_pause_the_monitor(): void
    {
        $mapped = $this->mapped(['type' => 'dns', 'hostname' => 'example.com', 'dns_resolve_type' => 'A', 'dns_resolve_server' => '1.1.1.1', 'port' => 53, 'conditions' => '[{"type":"expression","variable":"record","operator":"contains","value":"1"}]']);

        $this->assertTrue($mapped->pause);
        $this->assertSame(['conditions_ignored'], self::codes($mapped));
    }

    public function test_inverted_on_a_type_without_support_pauses(): void
    {
        $mapped = $this->mapped(['type' => 'push', 'upside_down' => 1]);

        $this->assertTrue($mapped->pause);
        $this->assertFalse($mapped->attributes['inverted']);
        $this->assertSame(['inverted_unsupported'], self::codes($mapped));
    }

    public function test_tls_and_domain_expiry_follow_the_monitor_flags(): void
    {
        $ctx = new MonitorMapContext(tlsExpiryDays: [3, 30]);

        $on = $this->mapped(['expiry_notification' => 1, 'domain_expiry_notification' => 1], $ctx);
        $this->assertTrue(self::config($on)['tls_checks']);
        $this->assertSame([3, 30], self::config($on)['tls_expiry_days']);
        $this->assertTrue(self::config($on)['domain_expiry']);

        $off = $this->mapped(['ignore_tls' => 1], $ctx);
        $this->assertFalse(self::config($off)['tls_checks']);
        $this->assertFalse(self::config($off)['tls_verify']);
        $this->assertFalse(self::config($off)['domain_expiry']);
        $this->assertArrayNotHasKey('tls_expiry_days', self::config($off));
    }

    public function test_ping_count_is_clamped_and_fits_the_timeout(): void
    {
        $ping = ['type' => 'ping', 'hostname' => 'example.com', 'url' => null];

        $this->assertSame(5, self::config($this->mapped($ping + ['ping_count' => 9]))['count']);
        $this->assertSame(1, self::config($this->mapped($ping + ['ping_count' => null]))['count']);

        $short = $this->mapped($ping + ['ping_count' => 4, 'timeout' => 2]);
        $this->assertSame(2, self::config($short)['count']);
        $this->assertContains('ping_count_lowered', self::codes($short));
    }

    public function test_dns_resolver_port_is_kept_when_not_53(): void
    {
        $dns = ['type' => 'dns', 'hostname' => 'example.com', 'dns_resolve_type' => 'MX', 'dns_resolve_server' => '9.9.9.9', 'port' => 5353];

        $this->assertSame('9.9.9.9:5353', self::config($this->mapped($dns))['resolver']);
        $this->assertSame('[2620:fe::fe]:5353', self::config($this->mapped(['dns_resolve_server' => '2620:fe::fe'] + $dns))['resolver']);
    }

    public function test_tags_resolve_and_a_second_value_is_dropped(): void
    {
        $ctx = new MonitorMapContext(
            monitorTags: [5 => [
                ['monitor_id' => 5, 'tag_id' => 1, 'value' => 'a'],
                ['monitor_id' => 5, 'tag_id' => 1, 'value' => 'b'],
                ['monitor_id' => 5, 'tag_id' => 2, 'value' => ''],
                ['monitor_id' => 5, 'tag_id' => 3, 'value' => null],
            ]],
            monitorNotifications: [5 => [1, 2]],
            resolveTag: fn (int $id): ?int => $id === 3 ? null : $id + 100,
            resolveChannel: fn (int $id): ?int => $id === 2 ? null : $id + 200,
        );

        $mapped = $this->mapped([], $ctx);

        $this->assertSame([['id' => 101, 'value' => 'a'], ['id' => 102, 'value' => null]], $mapped->attributes['tags']);
        $this->assertSame([201], $mapped->attributes['notification_channel_ids']);
        $this->assertSame(['tag_value_dropped', 'tag_not_imported', 'channel_not_imported'], self::codes($mapped));
    }

    public function test_parent_resolution_comes_from_the_context(): void
    {
        $ctx = new MonitorMapContext(resolveParent: fn (int $id): array => [null, Note::of('parent_not_imported', 'x')]);

        $mapped = $this->mapped(['parent' => 3], $ctx);

        $this->assertNull($mapped->attributes['parent_id']);
        $this->assertSame(['parent_not_imported'], self::codes($mapped));
    }
}
