<?php

namespace App\Monitoring\Net;

use Amp\Cancellation;
use Amp\Dns\DnsResolver;
use Amp\NullCancellation;
use Amp\Socket\ConnectContext;
use Amp\Socket\ConnectException;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\InternetAddress;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketConnector;

/**
 * Connects to hosts while refusing addresses inside blocked networks.
 *
 * Resolution and the policy check happen at connect time and the socket is opened to the checked IP, so a
 * hostname cannot be re-pointed at a blocked address between the check and the connection. A TLS context in the
 * ConnectContext keeps the original host name as peer name and SNI, because only the connection target is
 * replaced with the IP.
 */
final class GuardedConnector implements SocketConnector
{
    private readonly AllowedAddressResolver $addresses;

    private readonly SocketConnector $inner;

    public function __construct(DnsResolver $resolver, NetworkPolicy $policy, ?SocketConnector $inner = null)
    {
        $this->addresses = new AllowedAddressResolver($resolver, $policy);
        // The inner connector only ever receives literal IPs, so it never queries DNS itself.
        $this->inner = $inner ?? new DnsSocketConnector($resolver);
    }

    #[\Override]
    public function connect(SocketAddress|string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): Socket
    {
        $context ??= new ConnectContext;
        $cancellation ??= new NullCancellation;

        [$host, $port] = $this->split($uri);

        $ips = $this->addresses->resolve($host, $context->getDnsTypeRestriction(), $cancellation);

        foreach ($ips as $ip) {
            try {
                return $this->inner->connect(new InternetAddress($ip, $port), $context, $cancellation);
            } catch (ConnectException $e) {
                $failure = $e;
            }
        }

        throw $failure;
    }

    /**
     * @return array{0: string, 1: int<0, 65535>}
     */
    private function split(SocketAddress|string $uri): array
    {
        if ($uri instanceof InternetAddress) {
            return [$uri->getAddress(), $uri->getPort()];
        }

        $target = $uri instanceof SocketAddress ? $uri->toString() : preg_replace('#^[a-z0-9+.-]+://#i', '', $uri);

        if (is_string($target) && preg_match('/^\[(.+)\]:(\d+)$/', $target, $m) === 1
            || is_string($target) && preg_match('/^([^:]+):(\d+)$/', $target, $m) === 1) {
            $port = (int) $m[2];
            if ($port < 0 || $port > 65535) {
                throw new ConnectException('Invalid connection target');
            }

            return [$m[1], $port];
        }

        throw new ConnectException('Invalid connection target');
    }
}
