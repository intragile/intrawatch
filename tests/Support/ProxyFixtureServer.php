<?php

namespace Tests\Support;

use Amp\ByteStream\StreamException;
use Amp\Socket\InternetAddress;
use Amp\Socket\ServerSocket;
use Amp\Socket\Socket;
use Throwable;

use function Amp\async;
use function Amp\ByteStream\pipe;
use function Amp\Socket\connect;
use function Amp\Socket\listen;

/**
 * A minimal forward proxy on 127.0.0.1 that speaks HTTP CONNECT and SOCKS5 on the same port, both with
 * username and password authentication. Targets are always connected on 127.0.0.1.
 */
final class ProxyFixtureServer
{
    public const USER = 'proxyuser';

    public const PASSWORD = 'px-456';

    private readonly ServerSocket $server;

    /** @var list<Socket> */
    private array $sockets = [];

    /** @var list<string> Targets requested by clients, as host:port. */
    public array $targets = [];

    public function __construct()
    {
        $this->server = listen('127.0.0.1:0');
        $server = $this->server;
        async(function () use ($server): void {
            while ($client = $server->accept()) {
                $this->sockets[] = $client;
                async(function () use ($client): void {
                    try {
                        $this->serve($client);
                    } catch (Throwable) {
                        $client->close();
                    }
                })->ignore();
            }
        })->ignore();
    }

    public function address(): string
    {
        $address = $this->server->getAddress();
        assert($address instanceof InternetAddress);

        return '127.0.0.1:'.$address->getPort();
    }

    public function stop(): void
    {
        $this->server->close();
        foreach ($this->sockets as $socket) {
            $socket->close();
        }
        $this->sockets = [];
    }

    private function serve(Socket $client): void
    {
        $buffer = $client->read() ?? '';
        if ($buffer !== '' && $buffer[0] === "\x05") {
            $this->socks5($client, $buffer);
        } else {
            $this->connectMethod($client, $buffer);
        }
    }

    private function connectMethod(Socket $client, string $buffer): void
    {
        while (! str_contains($buffer, "\r\n\r\n")) {
            $chunk = $client->read();
            if ($chunk === null) {
                return;
            }
            $buffer .= $chunk;
        }

        [$head] = explode("\r\n\r\n", $buffer, 2);
        $lines = explode("\r\n", $head);
        $target = explode(' ', $lines[0])[1] ?? '';
        $authorized = false;
        foreach ($lines as $line) {
            if (strcasecmp(trim(strstr($line, ':', true) ?: ''), 'proxy-authorization') === 0) {
                $authorized = trim(substr($line, strpos($line, ':') + 1)) === 'Basic '.base64_encode(self::USER.':'.self::PASSWORD);
            }
        }

        if (! $authorized) {
            $client->write("HTTP/1.1 407 Proxy Authentication Required\r\nContent-Length: 0\r\n\r\n");
            $client->close();

            return;
        }

        $this->targets[] = $target;
        $upstream = $this->open((int) substr($target, strrpos($target, ':') + 1));
        $client->write("HTTP/1.1 200 Connection established\r\n\r\n");
        $this->splice($client, $upstream);
    }

    private function socks5(Socket $client, string $buffer): void
    {
        $buffer = $this->fill($client, $buffer, 2);
        $methods = substr($this->fill($client, $buffer, 2 + ord($buffer[1])), 2, ord($buffer[1]));
        if (! str_contains($methods, "\x02")) {
            $client->write("\x05\xff");
            $client->close();

            return;
        }
        $client->write("\x05\x02");

        $auth = $this->fill($client, '', 2);
        $auth = $this->fill($client, $auth, 2 + ord($auth[1]) + 1);
        $user = substr($auth, 2, ord($auth[1]));
        $passwordLength = ord($auth[2 + strlen($user)]);
        $auth = $this->fill($client, $auth, 3 + strlen($user) + $passwordLength);
        $password = substr($auth, 3 + strlen($user), $passwordLength);
        if ($user !== self::USER || $password !== self::PASSWORD) {
            $client->write("\x01\x01");
            $client->close();

            return;
        }
        $client->write("\x01\x00");

        $request = $this->fill($client, '', 5);
        if ($request[3] === "\x01") {
            $request = $this->fill($client, $request, 10);
            $host = (string) inet_ntop(substr($request, 4, 4));
            $port = (ord($request[8]) << 8) | ord($request[9]);
        } elseif ($request[3] === "\x03") {
            $hostLength = ord($request[4]);
            $request = $this->fill($client, $request, 5 + $hostLength + 2);
            $host = substr($request, 5, $hostLength);
            $port = (ord($request[5 + $hostLength]) << 8) | ord($request[6 + $hostLength]);
        } else {
            $client->write("\x05\x08\x00\x01\0\0\0\0\0\0");
            $client->close();

            return;
        }

        $this->targets[] = "{$host}:{$port}";
        $upstream = $this->open($port);
        $client->write("\x05\x00\x00\x01\x7f\x00\x00\x01".pack('n', $port));
        $this->splice($client, $upstream);
    }

    private function fill(Socket $client, string $buffer, int $length): string
    {
        while (strlen($buffer) < $length) {
            $chunk = $client->read();
            if ($chunk === null) {
                throw new StreamException('Client went away');
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function open(int $port): Socket
    {
        $upstream = connect("127.0.0.1:{$port}");
        $this->sockets[] = $upstream;

        return $upstream;
    }

    private function splice(Socket $client, Socket $upstream): void
    {
        $up = async(static fn () => pipe($client, $upstream));
        $down = async(static fn () => pipe($upstream, $client));
        try {
            $down->await();
        } catch (Throwable) {
            // The other side went away.
        } finally {
            $client->close();
            $upstream->close();
            $up->ignore();
        }
    }
}
