<?php

namespace Tests\Feature\Monitoring\Checks;

use Amp\Cancellation;
use Amp\Dns\DnsException;
use Amp\Dns\DnsRecord;
use Amp\Dns\DnsResolver;
use Amp\Future;
use Amp\NullCancellation;
use Amp\Socket\ConnectContext;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\InternetAddress;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketConnector;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Monitoring\Checks\CheckerRegistry;
use App\Monitoring\Checks\Http\CertificateFacts;
use App\Monitoring\Checks\Http\ClientFactory;
use App\Monitoring\Checks\HttpChecker;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Net\GuardedConnector;
use App\Monitoring\Net\NetworkPolicy;
use App\Monitoring\Worker\SystemClock;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HttpFixtureServer;
use Tests\Support\ProxyFixtureServer;
use Tests\Support\StubDnsResolver;
use Tests\Support\TestCertificates;
use Tests\TestCase;

use function Amp\async;
use function Amp\delay;

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

    private function assertUp(CheckOutcome $outcome, string $context = ''): void
    {
        $this->assertSame(MonitorStatus::Up, $outcome->status, trim($context.' '.$outcome->message));
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

    public function test_redirects_send_no_referer_and_drop_credentials_across_origins(): void
    {
        $s = $this->server();
        $checker = $this->checker(['localhost' => ['127.0.0.1']]);
        $secrets = [
            'bearer_token' => 'tok-xyz',
            'secret_headers' => ['X-Api-Key' => 'hdr-secret-789'],
        ];
        $auth = ['type' => 'bearer', 'username' => null];
        $absent = static fn (string $needle): array => ['value' => $needle, 'mode' => 'not_contains'];

        // Same origin: credentials are kept, which proves the echo route sees them, but no Referer is added.
        $same = rawurlencode("http://127.0.0.1:{$s->port()}/headers");
        $this->assertUp($this->probe(['url' => $s->url('/redirect-to?token=q-secret&target='.$same), 'auth' => $auth, 'keyword' => ['value' => '"authorization"', 'mode' => 'contains']], $secrets, checker: $checker));
        foreach (['"referer"', 'q-secret', '"accept-encoding"'] as $needle) {
            $this->assertUp($this->probe(['url' => $s->url('/redirect-to?token=q-secret&target='.$same), 'auth' => $auth, 'keyword' => $absent($needle)], $secrets, checker: $checker), $needle);
        }

        // Another origin (same port, other host name): no Referer, no Authorization, no secret header.
        $other = rawurlencode("http://localhost:{$s->port()}/headers");
        foreach (['"referer"', 'q-secret', '"authorization"', 'tok-xyz', '"x-api-key"', 'hdr-secret-789'] as $needle) {
            $outcome = $this->probe(['url' => $s->url('/redirect-to?token=q-secret&target='.$other), 'auth' => $auth, 'keyword' => $absent($needle)], $secrets, checker: $checker);
            $this->assertUp($outcome, $needle);
            $this->assertSame("http://localhost:{$s->port()}/headers", $outcome->facts['http.final_url']);
        }
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

    /**
     * @return array{0: HttpFixtureServer, 1: ProxyFixtureServer, 2: array<string, string>}
     */
    private function proxied(): array
    {
        $this->proxy = new ProxyFixtureServer;

        return [$this->server(), $this->proxy, ['proxy_password' => ProxyFixtureServer::PASSWORD]];
    }

    /**
     * @return array{url: string, username: string}
     */
    private function proxyConfig(string $scheme, ProxyFixtureServer $proxy): array
    {
        return ['url' => "{$scheme}://{$proxy->address()}", 'username' => ProxyFixtureServer::USER];
    }

    public function test_proxy_target_literal_metadata_ip_is_blocked_before_the_tunnel(): void
    {
        [$s, $proxyServer, $credentials] = $this->proxied();
        $checker = $this->checker(blocked: ['169.254.169.254/32']);

        foreach (['http', 'socks5'] as $scheme) {
            $this->assertDown('Blocked target', $this->probe(['url' => "http://169.254.169.254:{$s->port()}/fast", 'proxy' => $this->proxyConfig($scheme, $proxyServer)], $credentials, checker: $checker));
        }

        $this->assertSame([], $proxyServer->targets);
    }

    public function test_proxy_target_hostname_resolving_to_blocked_address_is_blocked(): void
    {
        [$s, $proxyServer, $credentials] = $this->proxied();
        $checker = $this->checker(['meta.test' => ['169.254.169.254']], ['169.254.169.254/32']);

        foreach (['http', 'socks5'] as $scheme) {
            $this->assertDown('Blocked target', $this->probe(['url' => "http://meta.test:{$s->port()}/fast", 'proxy' => $this->proxyConfig($scheme, $proxyServer)], $credentials, checker: $checker));
        }

        $this->assertSame([], $proxyServer->targets);
    }

    public function test_proxy_redirect_hop_to_metadata_ip_is_blocked(): void
    {
        [$s, $proxyServer, $credentials] = $this->proxied();
        $checker = $this->checker(blocked: ['169.254.169.254/32']);
        $target = rawurlencode("http://169.254.169.254:{$s->port()}/fast");

        foreach (['http', 'socks5'] as $scheme) {
            $this->assertDown('Blocked target', $this->probe(['url' => $s->url('/redirect-to?target='.$target), 'proxy' => $this->proxyConfig($scheme, $proxyServer)], $credentials, checker: $checker));
        }

        $this->assertSame(["127.0.0.1:{$s->port()}", "127.0.0.1:{$s->port()}"], $proxyServer->targets);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function numericMetadataHosts(): array
    {
        return [
            'decimal' => ['2852039166'],
            'hex' => ['0xa9fea9fe'],
            'short dotted' => ['169.254.43518'],
            'octal dotted' => ['0251.0376.0251.0376'],
            'mixed hex part' => ['169.0xfe.169.254'],
        ];
    }

    #[DataProvider('numericMetadataHosts')]
    public function test_proxy_target_numeric_ipv4_form_of_metadata_ip_is_blocked(string $host): void
    {
        [$s, $proxyServer, $credentials] = $this->proxied();
        $checker = $this->checker(blocked: ['169.254.169.254/32']);

        foreach (['http', 'socks5'] as $scheme) {
            $this->assertDown('Blocked target', $this->probe(['url' => "http://{$host}:{$s->port()}/fast", 'proxy' => $this->proxyConfig($scheme, $proxyServer)], $credentials, checker: $checker));
        }

        $this->assertSame([], $proxyServer->targets);
    }

    public function test_proxy_redirect_hop_to_numeric_metadata_ip_is_blocked(): void
    {
        [$s, $proxyServer, $credentials] = $this->proxied();
        $checker = $this->checker(blocked: ['169.254.169.254/32']);
        $target = rawurlencode("http://2852039166:{$s->port()}/fast");

        foreach (['http', 'socks5'] as $scheme) {
            $this->assertDown('Blocked target', $this->probe(['url' => $s->url('/redirect-to?target='.$target), 'proxy' => $this->proxyConfig($scheme, $proxyServer)], $credentials, checker: $checker));
        }

        $this->assertSame(["127.0.0.1:{$s->port()}", "127.0.0.1:{$s->port()}"], $proxyServer->targets);
    }

    public function test_proxy_target_numeric_ipv4_form_of_allowed_address_is_tunnelled(): void
    {
        [$s, $proxyServer, $credentials] = $this->proxied();
        $checker = $this->checker(blocked: ['169.254.169.254/32']);

        foreach (['http', 'socks5'] as $scheme) {
            $this->assertUp($this->probe(['url' => "http://2130706433:{$s->port()}/fast", 'proxy' => $this->proxyConfig($scheme, $proxyServer)], $credentials, checker: $checker), $scheme);
        }

        $this->assertSame(["2130706433:{$s->port()}", "2130706433:{$s->port()}"], $proxyServer->targets);
    }

    public function test_proxy_target_unknown_to_local_dns_is_still_tunnelled(): void
    {
        [$s, $proxyServer, $credentials] = $this->proxied();
        $checker = $this->checker(blocked: ['169.254.169.254/32']);

        foreach (['http', 'socks5'] as $scheme) {
            $this->assertUp($this->probe(['url' => "http://internal.test:{$s->port()}/fast", 'proxy' => $this->proxyConfig($scheme, $proxyServer)], $credentials, checker: $checker), $scheme);
        }

        $this->assertSame(["internal.test:{$s->port()}", "internal.test:{$s->port()}"], $proxyServer->targets);
    }

    public function test_proxied_latency_excludes_local_tunnel_target_lookup(): void
    {
        // The proxy resolves the target itself, so the worker's own best-effort lookup of it is not on the
        // request path and must not show up as latency, even when the local resolver never answers.
        [$s, $proxyServer, $credentials] = $this->proxied();

        foreach (['http', 'socks5'] as $scheme) {
            $lookups = 0;
            // A checker per scheme, so the second scheme is not helped by the first one's cached verdict.
            $checker = $this->checkerWithResolver($this->slowResolver([], $lookups, hang: true));
            $outcome = $this->probe(['url' => "http://slow.test:{$s->port()}/fast", 'proxy' => $this->proxyConfig($scheme, $proxyServer)], $credentials, checker: $checker);

            $this->assertUp($outcome, $scheme);
            $this->assertSame(1, $lookups, $scheme);
            $this->assertNotNull($outcome->latencyMs, $scheme);
            $this->assertLessThan(500, $outcome->latencyMs, $scheme);
        }
    }

    public function test_proxied_redirect_chain_looks_up_each_host_once(): void
    {
        [$s, $proxyServer, $credentials] = $this->proxied();
        $lookups = 0;
        $checker = $this->checkerWithResolver($this->slowResolver(['slow.test' => ['127.0.0.1']], $lookups, delay: 0.2));
        $target = rawurlencode("http://slow.test:{$s->port()}/fast");

        $outcome = $this->probe(['url' => "http://slow.test:{$s->port()}/redirect-to?target={$target}", 'proxy' => $this->proxyConfig('http', $proxyServer)], $credentials, checker: $checker);

        $this->assertUp($outcome);
        $this->assertSame(1, $lookups);
        $this->assertSame(["slow.test:{$s->port()}", "slow.test:{$s->port()}"], $proxyServer->targets);
    }

    public function test_proxy_blocked_hostname_stays_blocked_from_cache(): void
    {
        [$s, $proxyServer, $credentials] = $this->proxied();
        $lookups = 0;
        $checker = $this->checkerWithResolver($this->slowResolver(['meta.test' => ['169.254.169.254']], $lookups), ['169.254.169.254/32']);

        foreach (['http', 'socks5'] as $scheme) {
            $this->assertDown('Blocked target', $this->probe(['url' => "http://meta.test:{$s->port()}/fast", 'proxy' => $this->proxyConfig($scheme, $proxyServer)], $credentials, checker: $checker));
        }

        $this->assertSame(1, $lookups);
        $this->assertSame([], $proxyServer->targets);
    }

    /**
     * @param  list<string>  $blocked
     */
    private function checkerWithResolver(DnsResolver $resolver, array $blocked = []): HttpChecker
    {
        return new HttpChecker(new ClientFactory(new GuardedConnector($resolver, new NetworkPolicy($blocked)), caFile: $this->certificates()->caFile));
    }

    /**
     * A resolver that answers from a fixed map after $delay seconds, or with $hang never, counting its lookups.
     *
     * @param  array<string, non-empty-list<string>>  $hosts
     */
    private function slowResolver(array $hosts, int &$lookups, float $delay = 0.0, bool $hang = false): DnsResolver
    {
        $count = function () use (&$lookups): void {
            $lookups++;
        };

        return new class($hosts, $count, $delay, $hang) implements DnsResolver
        {
            /**
             * @param  array<string, non-empty-list<string>>  $hosts
             */
            public function __construct(
                private readonly array $hosts,
                private readonly \Closure $count,
                private readonly float $delay,
                private readonly bool $hang,
            ) {}

            public function resolve(string $name, ?int $typeRestriction = null, ?Cancellation $cancellation = null): array
            {
                ($this->count)();
                delay($this->hang ? 30 : $this->delay, true, $cancellation);

                $ips = $this->hosts[$name] ?? throw new DnsException("No such host {$name}");

                return array_map(static fn (string $ip): DnsRecord => new DnsRecord($ip, DnsRecord::A, null), $ips);
            }

            public function query(string $name, int $type, ?Cancellation $cancellation = null): array
            {
                return $this->resolve($name, $type, $cancellation);
            }
        };
    }

    public function test_unusable_proxy_fails_closed_instead_of_connecting_directly(): void
    {
        $s = $this->server();
        $closed = \Amp\Socket\listen('127.0.0.1:0');
        $address = $closed->getAddress();
        $this->assertInstanceOf(InternetAddress::class, $address);
        $closed->close();

        foreach (['http://127.0.0.1', 'ftp://127.0.0.1:21', 'not a url', "http://127.0.0.1:{$address->getPort()}", "socks5://127.0.0.1:{$address->getPort()}"] as $url) {
            $this->assertDown('Proxy connection failed', $this->probe(['url' => $s->url('/fast'), 'proxy' => ['url' => $url, 'username' => null]]));
        }
        $this->assertDown('Proxy connection failed', $this->probe(['url' => $s->url('/fast'), 'proxy' => ['username' => 'x']]));
    }

    public function test_mtls_key_file_is_deleted_when_the_request_cannot_be_built(): void
    {
        $certs = $this->certificates();
        $before = glob(sys_get_temp_dir().'/intrawatch-mtls-*') ?: [];

        $outcome = $this->probe(['url' => 'http://[not-a-host/', 'mtls' => true], ['mtls_cert' => $certs->clientCert, 'mtls_key' => $certs->clientKey]);

        $this->assertSame(MonitorStatus::Down, $outcome->status);
        $this->assertSame($before, glob(sys_get_temp_dir().'/intrawatch-mtls-*') ?: []);
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

    /**
     * An https checker with certificate facts, counting the connections it opens.
     *
     * @param  array<string, non-empty-list<string>>  $hosts
     * @return array{0: HttpChecker, 1: object{count: int}}
     */
    private function certificateChecker(TestCertificates $certs, array $hosts = ['secure.test' => ['127.0.0.1'], 'other.test' => ['127.0.0.1']]): array
    {
        $resolver = new StubDnsResolver($hosts);
        $counter = new class(new DnsSocketConnector($resolver)) implements SocketConnector
        {
            public int $count = 0;

            public function __construct(private readonly SocketConnector $inner) {}

            public function connect(SocketAddress|string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): Socket
            {
                $this->count++;

                return $this->inner->connect($uri, $context, $cancellation);
            }
        };
        $connector = new GuardedConnector($resolver, new NetworkPolicy, $counter);

        return [new HttpChecker(new ClientFactory($connector, caFile: $certs->caFile), new CertificateFacts(new SystemClock)), $counter];
    }

    public function test_https_monitor_gets_tls_facts_from_the_same_connection(): void
    {
        $this->certificates = new TestCertificates(['secure.test'], days: 20);
        $s = $this->server();
        [$checker, $connections] = $this->certificateChecker($this->certificates);

        $outcome = $this->probe(['url' => "https://secure.test:{$s->tlsPort()}/fast"], checker: $checker);

        $this->assertUp($outcome);
        $this->assertSame(1, $connections->count, 'The certificate is read from the request connection.');
        $this->assertContains($outcome->facts['tls.days_left'], [19, 20]);
        $this->assertIsString($outcome->facts['tls.not_after']);
        $this->assertSame('CN=IntraWatch Test CA', $outcome->facts['tls.issuer']);
        $this->assertSame('TLSv1.3', $outcome->facts['tls.protocol']);
        $this->assertIsString($outcome->facts['tls.cipher']);
        $this->assertArrayNotHasKey('tls.deprecated_protocols', $outcome->facts);
        $this->assertSame('30', $outcome->watch['tls.expiry_band']);
        $this->assertSame('CN=IntraWatch Test CA', $outcome->watch['tls.issuer']);
        $this->assertSame(64, strlen($outcome->watch['tls.fingerprint']));

        $plain = $this->probe(['url' => $s->url('/fast')], checker: $checker);
        $this->assertUp($plain);
        $this->assertArrayNotHasKey('tls.days_left', $plain->facts);
        $this->assertSame([], $plain->watch);

        $off = $this->probe(['url' => "https://secure.test:{$s->tlsPort()}/fast", 'tls_checks' => false], checker: $checker);
        $this->assertArrayNotHasKey('tls.days_left', $off->facts);
        $this->assertSame([], $off->watch);
    }

    public function test_certificate_expiring_within_seven_days_degrades_an_up_https_check(): void
    {
        $this->certificates = new TestCertificates(['secure.test'], days: 3);
        $s = $this->server();
        [$checker] = $this->certificateChecker($this->certificates);

        $outcome = $this->probe(['url' => "https://secure.test:{$s->tlsPort()}/fast"], checker: $checker);
        $this->assertSame(MonitorStatus::Degraded, $outcome->status, $outcome->message);
        // Three days from issue, so two or three whole days left depending on the second the check runs.
        $this->assertContains($outcome->message, ['Certificate expires in 2 days', 'Certificate expires in 3 days']);
        $this->assertSame('7', $outcome->watch['tls.expiry_band']);
        $this->assertSame(200, $outcome->facts['http.status']);

        // A failing request stays down; the certificate only ever degrades an otherwise healthy check.
        $this->assertDown('HTTP 503', $this->probe(['url' => "https://secure.test:{$s->tlsPort()}/status/503"], checker: $checker));

        // The band follows the monitor's own thresholds; the degrade threshold stays at seven days.
        $custom = $this->probe(['url' => "https://secure.test:{$s->tlsPort()}/fast", 'tls_expiry_days' => [60, 10]], checker: $checker);
        $this->assertSame('10', $custom->watch['tls.expiry_band']);
        $this->assertSame(MonitorStatus::Degraded, $custom->status);
    }

    public function test_certificate_facts_without_verification(): void
    {
        $this->certificates = new TestCertificates(['secure.test'], days: 20);
        $s = $this->server();
        [$checker] = $this->certificateChecker($this->certificates);

        $outcome = $this->probe(['url' => "https://other.test:{$s->tlsPort()}/fast", 'tls_verify' => false], checker: $checker);

        $this->assertUp($outcome);
        $this->assertContains($outcome->facts['tls.days_left'], [19, 20]);
        $this->assertArrayHasKey('tls.fingerprint', $outcome->watch);
    }

    public function test_container_wires_certificate_facts_into_the_http_checker(): void
    {
        $this->certificates = new TestCertificates(['secure.test'], days: 20);
        $s = $this->server();

        $checker = $this->app->make(CheckerRegistry::class)->for(MonitorType::Http);
        $outcome = $checker->check($this->spec(['url' => "https://127.0.0.1:{$s->tlsPort()}/fast", 'tls_verify' => false]), new NullCancellation);

        $this->assertUp($outcome);
        $this->assertContains($outcome->facts['tls.days_left'], [19, 20]);
    }
}
