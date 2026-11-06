<?php

namespace Tests\Support;

use Amp\Socket\InternetAddress;
use Amp\Socket\ResourceServerSocket;
use Amp\Socket\Socket;
use Throwable;

use function Amp\async;
use function Amp\delay;
use function Amp\Socket\listen;

/**
 * A scripted WHOIS (port 43 protocol) server on 127.0.0.1. Each query line is logged and answered with the
 * scripted text, or with an empty answer, then the connection is closed.
 */
final class WhoisFixtureServer
{
    private readonly ResourceServerSocket $socket;

    /** @var array<string, array{text: string, delay: float}> */
    private array $answers = [];

    /** @var list<string> */
    public array $queries = [];

    public function __construct()
    {
        $this->socket = listen('127.0.0.1:0');
        $socket = $this->socket;

        async(function () use ($socket): void {
            while ($client = $socket->accept()) {
                async(fn () => $this->serve($client))->ignore();
            }
        })->ignore();
    }

    public function server(string $host = 'whois.test'): string
    {
        $address = $this->socket->getAddress();
        assert($address instanceof InternetAddress);

        return "{$host}:{$address->getPort()}";
    }

    public function answer(string $query, string $text, float $delay = 0.0): void
    {
        $this->answers[strtolower($query)] = ['text' => $text, 'delay' => $delay];
    }

    public function stop(): void
    {
        $this->socket->close();
    }

    private function serve(Socket $client): void
    {
        try {
            $line = '';
            while (! str_contains($line, "\n") && ($chunk = $client->read()) !== null) {
                $line .= $chunk;
            }

            $query = strtolower(trim($line));
            $this->queries[] = $query;
            $answer = $this->answers[$query] ?? ['text' => '', 'delay' => 0.0];
            if ($answer['delay'] > 0) {
                delay($answer['delay']);
            }
            $client->write($answer['text']);
        } catch (Throwable) {
            // The client went away.
        } finally {
            $client->close();
        }
    }
}
