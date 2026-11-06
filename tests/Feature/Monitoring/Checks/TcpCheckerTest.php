<?php

namespace Tests\Feature\Monitoring\Checks;

use Amp\NullCancellation;
use Amp\Socket;
use Amp\Socket\InternetAddress;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Monitoring\Checks\TcpChecker;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Net\GuardedConnector;
use App\Monitoring\Net\NetworkPolicy;
use Tests\Support\MonitorSpecFactory;
use Tests\Support\StubDnsResolver;
use Tests\TestCase;

class TcpCheckerTest extends TestCase
{
    /**
     * @param  array<string, non-empty-list<string>>  $hosts
     */
    private function probe(string $host, int $port, int $timeout = 5, array $hosts = [], string ...$blocked): CheckOutcome
    {
        $checker = new TcpChecker(new GuardedConnector(new StubDnsResolver($hosts), new NetworkPolicy(array_values($blocked))));
        $spec = MonitorSpecFactory::of(MonitorType::Tcp, ['host' => $host, 'port' => $port], timeoutSeconds: $timeout);

        return $checker->check($spec, new NullCancellation);
    }

    public function test_blocked_network_denies_metadata_ip_even_via_hostname(): void
    {
        $outcome = $this->probe('evil.test', 80, 5, ['evil.test' => ['169.254.169.254']], '169.254.169.254/32');

        $this->assertSame(MonitorStatus::Down, $outcome->status);
        $this->assertSame('Blocked target', $outcome->message);
    }

    public function test_blocked_literal_ip_is_denied_and_allowed_address_is_preferred(): void
    {
        $server = Socket\listen('127.0.0.1:0');
        $address = $server->getAddress();
        $this->assertInstanceOf(InternetAddress::class, $address);
        $port = $address->getPort();

        $literal = $this->probe('127.0.0.1', $port, 5, [], '127.0.0.0/8');
        $this->assertSame('Blocked target', $literal->message);

        // One blocked and one allowed record: the allowed one is used.
        $mixed = $this->probe('mixed.test', $port, 5, ['mixed.test' => ['169.254.169.254', '127.0.0.1']], '169.254.169.254/32');
        $this->assertSame(MonitorStatus::Up, $mixed->status, $mixed->message);

        $server->close();
    }

    public function test_tcp_up_on_listening_port_and_down_on_closed(): void
    {
        $server = Socket\listen('127.0.0.1:0');
        $address = $server->getAddress();
        $this->assertInstanceOf(InternetAddress::class, $address);
        $port = $address->getPort();

        $up = $this->probe('127.0.0.1', $port);
        $this->assertSame(MonitorStatus::Up, $up->status);
        $this->assertGreaterThanOrEqual(0, $up->latencyMs);

        $server->close();

        $down = $this->probe('127.0.0.1', $port);
        $this->assertSame(MonitorStatus::Down, $down->status);
        $this->assertSame('Connection refused', $down->message);
    }

    public function test_unresolvable_host_is_down_without_leaking_the_name(): void
    {
        $outcome = $this->probe('missing.test', 80);

        $this->assertSame(MonitorStatus::Down, $outcome->status);
        $this->assertStringStartsWith('Connection failed', $outcome->message);
        $this->assertStringNotContainsString('missing.test', $outcome->message);
    }

    public function test_tcp_times_out_on_blackhole(): void
    {
        $started = microtime(true);
        $outcome = $this->probe('10.255.255.1', 80, 1);
        $elapsed = microtime(true) - $started;

        $this->assertSame(MonitorStatus::Down, $outcome->status);
        $this->assertLessThan(1.5, $elapsed);
        // Some networks reject the route immediately instead of dropping packets.
        $this->assertTrue(
            $outcome->message === 'Timed out after 1 s' || str_starts_with($outcome->message, 'Connection failed'),
            $outcome->message,
        );
    }

    public function test_ipv6_literal_target_connects(): void
    {
        try {
            $server = Socket\listen('[::1]:0');
        } catch (Socket\SocketException) {
            $this->markTestSkipped('IPv6 loopback is not available.');
        }
        $address = $server->getAddress();
        $this->assertInstanceOf(InternetAddress::class, $address);

        $outcome = $this->probe('::1', $address->getPort());
        $server->close();

        $this->assertSame(MonitorStatus::Up, $outcome->status, $outcome->message);
    }
}
