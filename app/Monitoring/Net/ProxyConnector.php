<?php

namespace App\Monitoring\Net;

use Amp\Cancellation;
use Amp\Socket\ConnectContext;
use Amp\Socket\ConnectException;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketConnector;

/**
 * Wraps a proxy tunnel connector so every failure to reach the target through the proxy surfaces as a
 * ProxyFailure. Without a tunnel the proxy configuration is unusable and every connection fails: a monitor
 * configured with a proxy never falls back to a direct connection.
 *
 * The target is checked against the network policy before the tunnel is asked for it, so a proxy cannot be used
 * to reach a blocked address: literal IPs are checked exactly, hostnames by a best-effort local resolution (see
 * GuardedConnector::assertTunnelTarget()). The hop to the proxy itself goes through the guarded connector inside
 * the tunnel.
 *
 * The time spent on that local check is counted separately (guardNanoseconds()): the proxy resolves the name
 * itself, so the local lookup is not on the request path and the checker keeps it out of the measured latency.
 * A ProxyConnector is created per check, so the counter covers exactly one check, redirect hops included.
 */
final class ProxyConnector implements SocketConnector
{
    public function __construct(
        private readonly ?SocketConnector $tunnel,
        private readonly GuardedConnector $targets,
    ) {}

    private int $guardNanoseconds = 0;

    /**
     * Nanoseconds spent checking tunnel targets against the network policy so far, blocked targets included.
     */
    public function guardNanoseconds(): int
    {
        return $this->guardNanoseconds;
    }

    #[\Override]
    public function connect(SocketAddress|string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): Socket
    {
        if ($this->tunnel === null) {
            throw new ProxyFailure('Invalid proxy configuration');
        }

        try {
            $started = hrtime(true);
            try {
                $this->targets->assertTunnelTarget($uri, $context, $cancellation);
            } finally {
                $this->guardNanoseconds += hrtime(true) - $started;
            }

            return $this->tunnel->connect($uri, $context, $cancellation);
        } catch (BlockedTarget|ProxyFailure $e) {
            throw $e;
        } catch (ConnectException $e) {
            throw new ProxyFailure('Proxy connection failed', 0, $e);
        }
    }
}
