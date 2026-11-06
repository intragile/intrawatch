<?php

namespace App\Monitoring\Net;

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\NullCancellation;
use Amp\Socket\ConnectContext;
use Amp\Socket\ConnectException;
use Amp\Socket\InternetAddress;
use Amp\Socket\ResourceSocket;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketConnector;
use Amp\TimeoutCancellation;

/**
 * Opens connections through a SOCKS5 proxy (RFC 1928), with optional username and password authentication
 * (RFC 1929).
 *
 * The target host name is sent to the proxy unresolved, so the proxy performs the DNS lookup. The returned
 * socket is the proxy connection itself, so TLS can be enabled on it with the original host as peer name.
 */
final class Socks5TunnelConnector implements SocketConnector
{
    private const VERSION = "\x05";

    private const NO_AUTH = "\x00";

    private const USER_PASS = "\x02";

    private const REPLIES = [
        1 => 'general failure',
        2 => 'connection not allowed by ruleset',
        3 => 'network unreachable',
        4 => 'host unreachable',
        5 => 'connection refused',
        6 => 'TTL expired',
        7 => 'command not supported',
        8 => 'address type not supported',
    ];

    public function __construct(
        private readonly string $proxyAddress,
        private readonly SocketConnector $connector,
        private readonly ?string $username = null,
        #[\SensitiveParameter]
        private readonly ?string $password = null,
    ) {}

    #[\Override]
    public function connect(SocketAddress|string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): Socket
    {
        $context ??= new ConnectContext;
        $cancellation ??= new NullCancellation;
        [$host, $port] = $this->target($uri);

        $socket = $this->connector->connect($this->proxyAddress, $context, $cancellation);
        $handshake = new CompositeCancellation($cancellation, new TimeoutCancellation($context->getConnectTimeout()));

        try {
            $this->negotiate($socket, $handshake);
            $this->request($socket, $host, $port, $handshake);
        } catch (CancelledException $e) {
            $socket->close();
            $cancellation->throwIfRequested();

            throw new ConnectException('Proxy handshake timed out', 0, $e);
        } catch (ConnectException $e) {
            $socket->close();

            throw $e;
        } catch (StreamException $e) {
            $socket->close();

            throw new ConnectException('Failed to connect to proxy: connection closed', 0, $e);
        }

        return $socket;
    }

    private function negotiate(Socket $socket, Cancellation $cancellation): void
    {
        $withCredentials = $this->username !== null && $this->username !== '';
        $socket->write(self::VERSION.($withCredentials ? "\x02".self::NO_AUTH.self::USER_PASS : "\x01".self::NO_AUTH));

        $reply = $this->readExactly($socket, 2, $cancellation);
        $method = $reply[1];

        if ($reply[0] !== self::VERSION || ($method !== self::NO_AUTH && ! ($withCredentials && $method === self::USER_PASS))) {
            throw new ConnectException('Failed to connect to proxy: no acceptable authentication method');
        }

        if ($method === self::NO_AUTH) {
            return;
        }

        $username = substr((string) $this->username, 0, 255);
        $password = substr((string) $this->password, 0, 255);
        $socket->write("\x01".chr(min(255, strlen($username))).$username.chr(min(255, strlen($password))).$password);

        if ($this->readExactly($socket, 2, $cancellation)[1] !== "\x00") {
            throw new ConnectException('Failed to connect to proxy: authentication rejected');
        }
    }

    private function request(Socket $socket, string $host, int $port, Cancellation $cancellation): void
    {
        $packed = @inet_pton($host);
        $address = match (true) {
            $packed !== false && strlen($packed) === 4 => "\x01".$packed,
            $packed !== false => "\x04".$packed,
            default => "\x03".chr(min(255, strlen($host))).$host,
        };

        $socket->write(self::VERSION."\x01\x00".$address.pack('n', $port));

        $reply = $this->readExactly($socket, 4, $cancellation);
        $code = ord($reply[1]);
        if ($reply[0] !== self::VERSION || $code !== 0) {
            throw new ConnectException('Failed to connect to proxy: '.(self::REPLIES[$code] ?? 'invalid reply'));
        }

        $length = match ($reply[3]) {
            "\x01" => 4,
            "\x04" => 16,
            "\x03" => ord($this->readExactly($socket, 1, $cancellation)),
            default => throw new ConnectException('Failed to connect to proxy: invalid reply'),
        };

        // The bound address and port are not needed.
        $this->readExactly($socket, $length + 2, $cancellation);
    }

    private function readExactly(Socket $socket, int $length, Cancellation $cancellation): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            // Reading no further than the reply keeps any bytes that follow it on the socket.
            $chunk = $socket instanceof ResourceSocket
                ? $socket->read($cancellation, max(1, $length - strlen($buffer)))
                : $socket->read($cancellation);
            if ($chunk === null) {
                throw new StreamException('Connection closed');
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function target(SocketAddress|string $uri): array
    {
        if ($uri instanceof InternetAddress) {
            return [$uri->getAddress(), $uri->getPort()];
        }

        $target = $uri instanceof SocketAddress ? $uri->toString() : (string) preg_replace('#^[a-z0-9+.-]+://#i', '', $uri);

        if (preg_match('/^\[(.+)\]:(\d+)$/', $target, $m) === 1 || preg_match('/^([^:]+):(\d+)$/', $target, $m) === 1) {
            if (strlen($m[1]) <= 255 && (int) $m[2] <= 65535) {
                return [$m[1], (int) $m[2]];
            }
        }

        throw new ConnectException('Invalid connection target');
    }
}
