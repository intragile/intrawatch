<?php

namespace Tests\Feature\Alerting;

use App\Alerting\Transport\AddressResolver;
use App\Alerting\Transport\DeliveryFailure;
use App\Alerting\Transport\OutboundHttp;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OutboundHttpTest extends TestCase
{
    private ?Process $server = null;

    private string $log = '';

    private int $port = 0;

    protected function tearDown(): void
    {
        $this->server?->stop(0);
        if ($this->log !== '' && is_file($this->log)) {
            unlink($this->log);
        }
        parent::tearDown();
    }

    private function startServer(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($probe);
        $this->port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $this->log = sys_get_temp_dir().'/outbound-http-'.bin2hex(random_bytes(4)).'.log';
        $this->server = new Process([PHP_BINARY, __DIR__.'/../../Support/blocking_http_server.php', (string) $this->port, $this->log]);
        $this->server->start();

        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', $this->port, $e, $s, 0.1);
            if ($c !== false) {
                fclose($c);

                return;
            }
            usleep(100_000);
        }
        $this->fail('Fixture server did not start: '.$this->server->getErrorOutput());
    }

    /** @return list<string> */
    private function hits(): array
    {
        return is_file($this->log) ? array_values(array_filter(explode("\n", (string) file_get_contents($this->log)))) : [];
    }

    /** @param array<string, list<string>> $map */
    private function fakeResolver(array $map): void
    {
        $this->app->instance(AddressResolver::class, new class($map) implements AddressResolver
        {
            /** @param array<string, list<string>> $map */
            public function __construct(private array $map) {}

            public function resolve(string $host): array
            {
                return $this->map[$host] ?? (filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : []);
            }
        });
    }

    private function http(): OutboundHttp
    {
        return $this->app->make(OutboundHttp::class);
    }

    private function failure(callable $fn): DeliveryFailure
    {
        try {
            $fn();
        } catch (DeliveryFailure $e) {
            return $e;
        }
        $this->fail('Expected DeliveryFailure');
    }

    public function test_blocked_and_metadata_targets_are_refused_before_connecting(): void
    {
        config(['monitoring.worker.blocked_networks' => ['127.0.0.0/8', '169.254.169.254/32']]);
        $this->fakeResolver(['mixed.test' => ['10.0.0.1', '127.0.0.1']]);

        foreach (['http://169.254.169.254/latest', 'http://127.0.0.1:1/', 'http://[::ffff:127.0.0.1]/', 'http://mixed.test/'] as $url) {
            $e = $this->failure(fn () => $this->http()->send('GET', $url));
            $this->assertSame('Blocked target', $e->getMessage(), $url);
            $this->assertTrue($e->permanent);
        }
    }

    public function test_policy_follows_config_changes_between_calls(): void
    {
        config(['monitoring.worker.blocked_networks' => []]);
        $this->startServer();
        $this->assertSame(200, $this->http()->send('GET', "http://127.0.0.1:{$this->port}/")->status);

        config(['monitoring.worker.blocked_networks' => ['127.0.0.0/8']]);
        $e = $this->failure(fn () => $this->http()->send('GET', "http://127.0.0.1:{$this->port}/"));
        $this->assertSame('Blocked target', $e->getMessage());
    }

    public function test_invalid_urls_and_dns_failures(): void
    {
        $this->fakeResolver([]);
        foreach (['ftp://example.test/', 'http://user:pw@example.test/', 'not a url', 'http:///x'] as $url) {
            $e = $this->failure(fn () => $this->http()->send('GET', $url));
            $this->assertSame('Invalid target URL', $e->getMessage(), $url);
            $this->assertTrue($e->permanent);
        }

        $e = $this->failure(fn () => $this->http()->send('GET', 'http://nxdomain.test/'));
        $this->assertSame('DNS resolution failed', $e->getMessage());
        $this->assertFalse($e->permanent);
    }

    public function test_connection_is_pinned_to_vetted_addresses(): void
    {
        config(['monitoring.worker.blocked_networks' => []]);
        $this->startServer();
        $this->fakeResolver(['hook.test' => ['127.0.0.1']]);

        $response = $this->http()->send('POST', "http://hook.test:{$this->port}/json", ['json' => ['a' => 1]]);

        $this->assertSame(200, $response->status);
        $this->assertSame(['ok' => true], $response->json());
    }

    public function test_redirects_are_not_followed(): void
    {
        config(['monitoring.worker.blocked_networks' => []]);
        $this->startServer();

        $e = $this->failure(fn () => $this->http()->send('GET', "http://127.0.0.1:{$this->port}/redirect"));

        $this->assertSame('HTTP 302', $e->getMessage());
        $this->assertTrue($e->permanent);
        $this->assertContains('/redirect', $this->hits());
        $this->assertNotContains('/other', $this->hits());
    }

    public function test_hanging_endpoint_times_out_within_budget(): void
    {
        config(['monitoring.worker.blocked_networks' => []]);
        $this->startServer();

        $start = microtime(true);
        $e = $this->failure(fn () => $this->http()->send('GET', "http://127.0.0.1:{$this->port}/hang"));

        $this->assertLessThan(11, microtime(true) - $start);
        $this->assertSame('Timed out', $e->getMessage());
        $this->assertFalse($e->permanent);
    }

    public function test_status_classification_and_retry_after(): void
    {
        config(['monitoring.worker.blocked_networks' => []]);
        $this->startServer();
        $base = "http://127.0.0.1:{$this->port}";

        $e = $this->failure(fn () => $this->http()->send('GET', "{$base}/ratelimit"));
        $this->assertSame('HTTP 429', $e->getMessage());
        $this->assertFalse($e->permanent);
        $this->assertSame(30, $e->retryAfter);

        $this->assertSame(300, $this->failure(fn () => $this->http()->send('GET', "{$base}/ratelimit-long"))->retryAfter);

        $e = $this->failure(fn () => $this->http()->send('GET', "{$base}/status/404"));
        $this->assertTrue($e->permanent);
        $this->assertNull($e->retryAfter);

        foreach ([503, 408] as $code) {
            $e = $this->failure(fn () => $this->http()->send('GET', "{$base}/status/{$code}"));
            $this->assertSame("HTTP {$code}", $e->getMessage());
            $this->assertFalse($e->permanent);
        }

        $this->assertSame(204, $this->http()->send('GET', "{$base}/status/204")->status);
    }

    public function test_body_is_capped(): void
    {
        config(['monitoring.worker.blocked_networks' => []]);
        $this->startServer();

        $response = $this->http()->send('GET', "http://127.0.0.1:{$this->port}/big");

        $this->assertSame(65536, strlen($response->body));
    }

    public function test_connection_refused_is_retryable(): void
    {
        config(['monitoring.worker.blocked_networks' => []]);
        $this->startServer();
        $port = $this->port;
        $this->server?->stop(0);

        $e = $this->failure(fn () => $this->http()->send('GET', "http://127.0.0.1:{$port}/"));

        $this->assertSame('Connection failed', $e->getMessage());
        $this->assertFalse($e->permanent);
    }

    public function test_delivery_failure_message_is_bounded(): void
    {
        $e = new DeliveryFailure('HTTP 400', detail: str_repeat('x', 900));

        $this->assertSame(500, mb_strlen($e->message()));
        $this->assertStringStartsWith('HTTP 400: xx', $e->message());
    }
}
