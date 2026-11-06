<?php

namespace Tests\Support;

use Amp\Socket\UdpSocket;

use function Amp\async;
use function Amp\Socket\bindUdpSocket;

/**
 * A UDP name server that answers every A question with one fixed address.
 */
final class DnsFixtureServer
{
    public int $received = 0;

    private UdpSocket $socket;

    public function __construct(private readonly string $ip)
    {
        $this->socket = bindUdpSocket('127.0.0.1:0');

        async(function (): void {
            while ($datagram = $this->socket->receive()) {
                [$peer, $query] = $datagram;
                $this->received++;
                $this->socket->send($peer, $this->answer($query));
            }
        })->ignore();
    }

    public function port(): int
    {
        return $this->socket->getAddress()->getPort();
    }

    public function close(): void
    {
        $this->socket->close();
    }

    private function answer(string $query): string
    {
        $header = substr($query, 0, 2).pack('nnnnn', 0x8180, 1, 1, 0, 0);
        // The question section is copied verbatim; the answer points back at the name in it.
        $question = substr($query, 12);
        $answer = "\xC0\x0C".pack('nnNn', 1, 1, 60, 4).(string) inet_pton($this->ip);

        return $header.$question.$answer;
    }
}
