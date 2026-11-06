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
 */
final class ProxyConnector implements SocketConnector
{
    public function __construct(private readonly ?SocketConnector $tunnel) {}

    #[\Override]
    public function connect(SocketAddress|string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): Socket
    {
        if ($this->tunnel === null) {
            throw new ProxyFailure('Invalid proxy configuration');
        }

        try {
            return $this->tunnel->connect($uri, $context, $cancellation);
        } catch (BlockedTarget|ProxyFailure $e) {
            throw $e;
        } catch (ConnectException $e) {
            throw new ProxyFailure('Proxy connection failed', 0, $e);
        }
    }
}
