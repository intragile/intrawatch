<?php

namespace Tests\Feature\Monitoring\Checks;

use Amp\Future;
use Amp\NullCancellation;
use Amp\Socket\InternetAddress;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Monitoring\Checks\Http\ClientFactory;
use App\Monitoring\Checks\HttpChecker;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Net\GuardedConnector;
use App\Monitoring\Net\NetworkPolicy;
use Tests\Support\HttpFixtureServer;
use Tests\Support\ProxyFixtureServer;
use Tests\Support\StubDnsResolver;
use Tests\Support\TestCertificates;
use Tests\TestCase;

use function Amp\async;

class HttpCheckerTest extends TestCase
{
    private ?HttpFixtureServer $server = null;

    private ?ProxyFixtureServer $proxy = null;

    private ?TestCertificates $certificates = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->proxy?->stop();
        $this->certificates?->cleanup();

        parent::tearDown();
    }

    private function server(): HttpFixtureServer
    {
        return $this->server ??= new HttpFixtureServer($this->certificates());
    }

    private function certificates(): TestCertificates
    {
        return $this->certificates ??= new TestCertificates(['secure.test']);
    }

    /**
     * @param  array<string, non-empty-list<string>>  $hosts
     * @param  list<string>  $blocked
     */
    private function checker(array $hosts = [], array $blocked = []): HttpChecker
    {
        $connector = new GuardedConnector(new StubDnsResolver($hosts), new NetworkPolicy($blocked));

        return new HttpChecker(new ClientFactory($connector, caFile: $this->certificates()->caFile));
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $secrets
     */
    private function spec(array $config, array $secrets = [], int $timeout = 5): MonitorSpec
    {
        return new MonitorSpec(
            id: 1,
            workspaceId: 1,
            type: MonitorType::Http,
            config: $config + [
                'method' => 'GET',
                'headers' => [],
                'body' => null,
                'accepted_status' => ['200-299'],
                'max_redirects' => 5,
                'keyword' => null,
                'json_query' => null,
                'header_checks' => [],
                'auth' => ['type' => 'none', 'username' => null],
                'mtls' => false,
                'proxy' => null,
                'tls_verify' => true,
                'tls_checks' => true,
                'tls_expiry_days' => [30, 14, 7, 1],
                'domain_expiry' => true,
            ],
            secrets: $secrets,
            intervalSeconds: 60,
            timeoutSeconds: $timeout,
            retries: 1,
            retryIntervalSeconds: 60,
            degradedThresholdMs: null,
            inverted: false,
            parentId: null,
            updatedAt: 0,
            pushToken: null,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $secrets
     */
    private function probe(array $config, array $secrets = [], int $timeout = 5, ?HttpChecker $checker = null): CheckOutcome
    {
        return ($checker ?? $this->checker())->check($this->spec($config, $secrets, $timeout), new NullCancellation);
    }

    private function assertDown(string $message, CheckOutcome $outcome): void
    {
        $this->assertSame(MonitorStatus::Down, $outcome->status, $outcome->message);
        $this->assertSame($message, $outcome->message);
    }

    private function assertUp(CheckOutcome $outcome): void
    {
        $this->assertSame(MonitorStatus::Up, $outcome->status, $outcome->message);
    }

    public function test_status_ranges_keyword_and_json_query(): void
    {
        $s = $this->server();

        $up = $this->probe(['url' => $s->url('/status/204'), 'accepted_status' => ['200-299']]);
        $this->assertUp($up);
        $this->assertSame(204, $up->facts['http.status']);
        $this->assertNotNull($up->latencyMs);

        $this->assertDown('HTTP 301', $this->probe(['url' => $s->url('/redirect/1'), 'max_redirects' => 0, 'accepted_status' => ['200-299']]));
        $this->assertDown('HTTP 503', $this->probe(['url' => $s->url('/status/503')]));
        $this->assertUp($this->probe(['url' => $s->url('/status/503'), 'accepted_status' => ['200', '500-599']]));

        $body = $s->url('/body?content='.rawurlencode('hello world'));
        $this->assertUp($this->probe(['url' => $body, 'keyword' => ['value' => 'world', 'mode' => 'contains']]));
        $this->assertDown('Keyword not found', $this->probe(['url' => $body, 'keyword' => ['value' => 'WORLD', 'mode' => 'contains', 'case_sensitive' => true]]));
        $this->assertUp($this->probe(['url' => $body, 'keyword' => ['value' => 'WORLD', 'mode' => 'contains', 'case_sensitive' => false]]));
        $this->assertDown('Forbidden keyword found', $this->probe(['url' => $body, 'keyword' => ['value' => 'world', 'mode' => 'not_contains']]));

        $json = $s->url('/json?content='.rawurlencode('{"a":{"b":5},"tags":["x","y"],"name":"alpha","items":[{"n":1},{"n":2}]}'));
        $this->assertUp($this->probe(['url' => $json, 'json_query' => ['path' => '$.a.b', 'operator' => '>', 'expected' => 3]]));
        $this->assertUp($this->probe(['url' => $json, 'json_query' => ['path' => '$.a.b', 'operator' => '==', 'expected' => '5']]));
        $failed = $this->probe(['url' => $json, 'json_query' => ['path' => '$.a.b', 'operator' => '==', 'expected' => 4]]);
        $this->assertDown('JSON query $.a.b == 4 failed (got 5)', $failed);
        $this->assertStringContainsString('got 5', $failed->message);
        $this->assertUp($this->probe(['url' => $json, 'json_query' => ['path' => '$.tags', 'operator' => 'contains', 'expected' => 'y']]));
        $this->assertUp($this->probe(['url' => $json, 'json_query' => ['path' => '$.name', 'operator' => 'contains', 'expected' => 'lph']]));
        $this->assertUp($this->probe(['url' => $json, 'json_query' => ['path' => '$.items[*].n', 'operator' => '==', 'expected' => 1]]));
        $this->assertUp($this->probe(['url' => $json, 'json_query' => ['path' => '$.a', 'operator' => 'exists', 'expected' => null]]));
        $this->assertDown('JSON query $.missing exists failed (got no match)', $this->probe(['url' => $json, 'json_query' => ['path' => '$.missing', 'operator' => 'exists', 'expected' => null]]));
        $this->assertDown('JSON query $.a.b > 1 failed (got invalid JSON)', $this->probe(['url' => $body, 'json_query' => ['path' => '$.a.b', 'operator' => '>', 'expected' => 1]]));

        $long = $s->url('/json?content='.rawurlencode(json_encode(['v' => str_repeat('z', 300)], JSON_THROW_ON_ERROR)));
        $truncated = $this->probe(['url' => $long, 'json_query' => ['path' => '$.v', 'operator' => '==', 'expected' => 'q']]);
        $this->assertSame('JSON query $.v == q failed (got '.str_repeat('z', 100).')', $truncated->message);
    }

    public function test_header_checks_and_methods_with_body(): void
    {
        $s = $this->server();

        $outcome = $this->probe([
            'url' => $s->url('/echo'),
            'method' => 'POST',
            'body' => '{"ping":true}',
            'headers' => ['Content-Type' => 'application/json'],
            'keyword' => ['value' => '"ping":true', 'mode' => 'contains'],
            'header_checks' => [
                ['name' => 'X-Ver', 'operator' => 'equals', 'value' => '2'],
                ['name' => 'x-method', 'operator' => 'contains', 'value' => 'POS'],
                ['name' => 'X-Ver', 'operator' => 'exists', 'value' => null],
                ['name' => 'X-Missing', 'operator' => 'not_exists', 'value' => null],
            ],
        ]);
        $this->assertUp($outcome);

        $this->assertDown('Header X-Ver check failed', $this->probe([
            'url' => $s->url('/echo'),
            'header_checks' => [['name' => 'X-Ver', 'operator' => 'equals', 'value' => '3']],
        ]));
        $this->assertDown('Header X-Ver check failed', $this->probe([
            'url' => $s->url('/echo'),
            'header_checks' => [['name' => 'X-Ver', 'operator' => 'not_exists', 'value' => null]],
        ]));
        $this->assertUp($this->probe(['url' => $s->url('/echo'), 'method' => 'HEAD']));
    }

    public function test_basic_bearer_and_mtls_auth(): void
    {
        $s = $this->server();
        $certs = $this->certificates();
        $tls = $this->checker(['secure.test' => ['127.0.0.1']]);

        $this->assertDown('HTTP 401', $this->probe(['url' => $s->url('/basic')]));
        $this->assertUp($this->probe(
            ['url' => $s->url('/basic'), 'auth' => ['type' => 'basic', 'username' => HttpFixtureServer::BASIC_USER]],
            ['basic_password' => HttpFixtureServer::BASIC_PASSWORD],
        ));

        $this->assertDown('HTTP 401', $this->probe(['url' => $s->url('/bearer')]));
        $this->assertUp($this->probe(
            ['url' => $s->url('/bearer'), 'auth' => ['type' => 'bearer', 'username' => null]],
            ['bearer_token' => HttpFixtureServer::BEARER_TOKEN],
        ));

        $this->assertDown('HTTP 401', $this->probe(['url' => $s->url('/header')]));
        $this->assertUp($this->probe(
            ['url' => $s->url('/header'), 'headers' => [HttpFixtureServer::SECRET_HEADER => 'overridden']],
            ['secret_headers' => [HttpFixtureServer::SECRET_HEADER => HttpFixtureServer::SECRET_HEADER_VALUE]],
        ));

        $this->assertDown('HTTP 401', $this->probe(['url' => "https://secure.test:{$s->tlsPort()}/mtls"], checker: $tls));

        // The mTLS listener rejects a client without a certificate during or right after the handshake.
        $mtlsUrl = "https://secure.test:{$s->mtlsPort()}/mtls";
        $rejected = $this->probe(['url' => $mtlsUrl], checker: $tls);
        $this->assertSame(MonitorStatus::Down, $rejected->status);
        $this->assertContains($rejected->message, ['Connection closed before the response was complete', 'TLS handshake failed: handshake error', 'TLS handshake failed: tlsv13 alert certificate required']);

        $before = glob(sys_get_temp_dir().'/intrawatch-mtls-*') ?: [];
        $this->assertUp($this->probe(
            ['url' => $mtlsUrl, 'mtls' => true],
            ['mtls_cert' => $certs->clientCert, 'mtls_key' => $certs->clientKey],
            checker: $tls,
        ));
        $this->assertSame($before, glob(sys_get_temp_dir().'/intrawatch-mtls-*') ?: [], 'The temporary key file must be deleted.');
    }

    public function test_tls_peer_name_is_the_hostname_not_the_resolved_ip(): void
    {
        $s = $this->server();
        $checker = $this->checker(['secure.test' => ['127.0.0.1'], 'other.test' => ['127.0.0.1']]);

        $this->assertUp($this->probe(['url' => "https://secure.test:{$s->tlsPort()}/fast"], checker: $checker));

        $mismatch = $this->probe(['url' => "https://other.test:{$s->tlsPort()}/fast"], checker: $checker);
        $this->assertSame(MonitorStatus::Down, $mismatch->status);
        $this->assertStringStartsWith('TLS handshake failed: ', $mismatch->message);
        $this->assertStringNotContainsString('other.test', $mismatch->message);
        $this->assertStringNotContainsString('127.0.0.1', $mismatch->message);

        $this->assertUp($this->probe(['url' => "https://other.test:{$s->tlsPort()}/fast", 'tls_verify' => false], checker: $checker));

        // Without the test CA the certificate is untrusted.
        $untrusted = (new HttpChecker(new ClientFactory(new GuardedConnector(new StubDnsResolver(['secure.test' => ['127.0.0.1']]), new NetworkPolicy))))
            ->check($this->spec(['url' => "https://secure.test:{$s->tlsPort()}/fast"]), new NullCancellation);
        $this->assertStringStartsWith('TLS handshake failed: ', $untrusted->message);
    }

    public function test_redirect_chain_limit(): void
    {
        $s = $this->server();

        $this->assertDown('Too many redirects', $this->probe(['url' => $s->url('/redirect/3'), 'max_redirects' => 2]));

        $followed = $this->probe(['url' => $s->url('/redirect/3'), 'max_redirects' => 3]);
        $this->assertUp($followed);
        $this->assertSame($s->url('/redirect/0'), $followed->facts['http.final_url']);
    }

    public function test_network_policy_applies_to_every_redirect_hop(): void
    {
        $s = $this->server();
        $checker = $this->checker(['evil.test' => ['127.0.0.2']], ['127.0.0.2/32']);

        $this->assertDown('Blocked target', $this->probe(['url' => "http://evil.test:{$s->port()}/fast"], checker: $checker));

        $target = rawurlencode("http://evil.test:{$s->port()}/fast");
        $this->assertDown('Blocked target', $this->probe(['url' => $s->url('/redirect-to?target='.$target)], checker: $checker));
    }

    public function test_connection_refused(): void
    {
        $socket = \Amp\Socket\listen('127.0.0.1:0');
        $address = $socket->getAddress();
        $this->assertInstanceOf(InternetAddress::class, $address);
        $socket->close();

        $this->assertDown('Connection refused', $this->probe(['url' => "http://127.0.0.1:{$address->getPort()}/"]));
    }

    public function test_hanging_server_times_out_without_delaying_other_checks(): void
    {
        $s = $this->server();
        $checker = $this->checker();

        $hanging = async(fn (): array => $this->timedProbe($checker, $s->hangingUrl()));
        $drip = async(fn (): array => $this->timedProbe($checker, $s->url('/drip')));
        $fast = async(fn (): array => $this->timedProbe($checker, $s->url('/fast')));

        [$hangOutcome, $hangSeconds] = $this->settle($hanging);
        [$dripOutcome, $dripSeconds] = $this->settle($drip);
        [$fastOutcome, $fastSeconds] = $this->settle($fast);

        $this->assertDown('Timed out after 2 s', $hangOutcome);
        $this->assertGreaterThanOrEqual(1.9, $hangSeconds);
        $this->assertLessThan(2.5, $hangSeconds);

        $this->assertDown('Timed out after 2 s', $dripOutcome);
        $this->assertGreaterThanOrEqual(1.9, $dripSeconds);
        $this->assertLessThan(2.5, $dripSeconds);

        $this->assertUp($fastOutcome);
        $this->assertLessThan(0.5, $fastSeconds);
    }

    /**
     * @return array{0: CheckOutcome, 1: float} The outcome and the seconds it took.
     */
    private function timedProbe(HttpChecker $checker, string $url): array
    {
        $started = hrtime(true);
        $outcome = $this->probe(['url' => $url], timeout: 2, checker: $checker);

        return [$outcome, (hrtime(true) - $started) / 1e9];
    }

    /**
     * @param  Future<mixed>  $future
     * @return array{0: CheckOutcome, 1: float}
     */
    private function settle(Future $future): array
    {
        $result = $future->await();
        $this->assertIsArray($result);
        [$outcome, $seconds] = $result;
        $this->assertInstanceOf(CheckOutcome::class, $outcome);
        $this->assertIsFloat($seconds);

        return [$outcome, $seconds];
    }

    public function test_requests_go_through_http_and_socks5_proxies(): void
    {
        $s = $this->server();
        $this->proxy = $proxyServer = new ProxyFixtureServer;
        $credentials = ['proxy_password' => ProxyFixtureServer::PASSWORD];

        foreach (['http', 'socks5'] as $scheme) {
            $proxy = ['url' => "{$scheme}://{$proxyServer->address()}", 'username' => ProxyFixtureServer::USER];

            $this->assertUp($this->probe(['url' => $s->url('/fast'), 'proxy' => $proxy], $credentials));
            $this->assertDown('Proxy connection failed', $this->probe(['url' => $s->url('/fast'), 'proxy' => $proxy], ['proxy_password' => 'wrong']));
        }

        $this->assertSame(["127.0.0.1:{$s->port()}", "127.0.0.1:{$s->port()}"], $proxyServer->targets);
    }

    public function test_result_message_never_contains_credentials(): void
    {
        $s = $this->server();
        $this->proxy = $proxyServer = new ProxyFixtureServer;
        $secrets = [
            'basic_password' => 'pw-123',
            'bearer_token' => 'tok-xyz',
            'proxy_password' => 'px-wrong-456',
            'secret_headers' => ['X-Api-Key' => 'hdr-secret-789'],
        ];
        $needles = ['pw-123', 'tok-xyz', 'px-wrong-456', 'hdr-secret-789', 'url-secret', 'userpw', 'q-secret'];

        $basic = ['type' => 'basic', 'username' => 'bob'];
        $bearer = ['type' => 'bearer', 'username' => null];
        $outcomes = [
            $this->probe(['url' => $s->url('/status/500?token=q-secret'), 'auth' => $basic], $secrets),
            $this->probe(['url' => $s->url('/body?content=x&token=q-secret'), 'auth' => $bearer, 'keyword' => ['value' => 'tok-xyz', 'mode' => 'contains']], $secrets),
            $this->probe(['url' => $s->url('/body?content=pw-123'), 'auth' => $basic, 'keyword' => ['value' => 'pw-123', 'mode' => 'not_contains']], $secrets),
            $this->probe(['url' => $s->url('/echo?token=q-secret'), 'auth' => $bearer, 'header_checks' => [['name' => 'X-Ver', 'operator' => 'equals', 'value' => 'tok-xyz']]], $secrets),
            $this->probe(['url' => $s->url('/fast?token=q-secret'), 'auth' => $basic, 'proxy' => ['url' => "http://{$proxyServer->address()}", 'username' => 'proxyuser']], $secrets),
            $this->probe(['url' => $s->url('/fast?token=q-secret'), 'auth' => $basic, 'proxy' => ['url' => "socks5://{$proxyServer->address()}", 'username' => 'proxyuser']], $secrets),
            $this->probe(['url' => "http://user:userpw@127.0.0.1:{$s->port()}/fast?token=url-secret", 'auth' => $bearer], $secrets),
            $this->probe(['url' => $s->hangingUrl().'?token=q-secret', 'auth' => $bearer], $secrets, timeout: 1),
            $this->probe(['url' => "https://other.test:{$s->tlsPort()}/?token=q-secret", 'auth' => $bearer], $secrets, checker: $this->checker(['other.test' => ['127.0.0.1']])),
            $this->probe(['url' => "https://secure.test:{$s->mtlsPort()}/mtls?token=q-secret", 'mtls' => true], $secrets + ['mtls_cert' => 'not a cert', 'mtls_key' => 'not a key'], checker: $this->checker(['secure.test' => ['127.0.0.1']])),
        ];

        foreach ($outcomes as $i => $outcome) {
            // Outcome 6 succeeds: userinfo in the URL is dropped, never sent or reported.
            $this->assertSame($i === 6 ? MonitorStatus::Up : MonitorStatus::Down, $outcome->status, "Outcome {$i}: {$outcome->message}");
            $serialized = json_encode([$outcome->message, $outcome->facts, $outcome->watch], JSON_THROW_ON_ERROR);
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $serialized, "Outcome {$i} leaks {$needle}");
            }
            $this->assertStringNotContainsString('token=', $serialized, "Outcome {$i} leaks the query string");
        }

        $this->assertSame("http://127.0.0.1:{$s->port()}/fast", $outcomes[6]->facts['http.final_url']);
    }

    public function test_body_read_capped_at_one_mebibyte(): void
    {
        $s = $this->server();
        $checker = $this->checker();

        $this->assertUp($this->probe(['url' => $s->url('/big'), 'keyword' => ['value' => 'aaaa', 'mode' => 'contains']], checker: $checker));

        memory_reset_peak_usage();
        $baseline = memory_get_usage();
        $outcome = $this->probe(['url' => $s->url('/big'), 'keyword' => ['value' => 'NEEDLE', 'mode' => 'contains']], checker: $checker);

        $this->assertDown('Keyword not found', $outcome);
        $this->assertLessThan(4 * 1048576, memory_get_peak_usage() - $baseline);
    }
}
