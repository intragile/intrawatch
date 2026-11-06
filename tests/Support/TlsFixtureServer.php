<?php

namespace Tests\Support;

use Amp\Socket\BindContext;
use Amp\Socket\Certificate;
use Amp\Socket\InternetAddress;
use Amp\Socket\ResourceSocket;
use Amp\Socket\ServerSocket;
use Amp\Socket\ServerTlsContext;
use Amp\Socket\Socket;
use Throwable;

use function Amp\async;
use function Amp\Socket\listen;

/**
 * A TLS server on 127.0.0.1 for TLS check tests. Each connection completes one handshake and is closed.
 *
 * Optional modes: a STARTTLS dialog (smtp, imap or pop3) before the handshake, a STARTTLS dialog that does
 * not offer the extension, a server that floods the dialog, and a server that accepts and never answers.
 * The certificate can be swapped between connections.
 */
final class TlsFixtureServer
{
    private readonly ServerSocket $server;

    /** @var list<Socket> */
    private array $sockets = [];

    private string $certificate;

    /**
     * @param  string  $mode  none, smtp, imap, pop3, smtp-without-starttls, flood or hang
     * @param  array<string, mixed>  $sslOptions  Extra ssl stream context options applied to every connection.
     */
    public function __construct(string $certificate, private readonly string $mode = 'none', private readonly array $sslOptions = [])
    {
        $this->certificate = $certificate;
        $tls = (new ServerTlsContext)->withDefaultCertificate(new Certificate($certificate));
        $this->server = listen('127.0.0.1:0', (new BindContext)->withTlsContext($tls));
        $server = $this->server;

        async(function () use ($server): void {
            while ($client = $server->accept()) {
                $this->sockets[] = $client;
                async(function () use ($client): void {
                    try {
                        $this->serve($client);
                    } catch (Throwable) {
                        // The client went away or the handshake failed; both are expected in tests.
                    } finally {
                        if ($this->mode !== 'hang') {
                            $client->close();
                        }
                    }
                })->ignore();
            }
        })->ignore();
    }

    public function port(): int
    {
        $address = $this->server->getAddress();
        assert($address instanceof InternetAddress);

        return $address->getPort();
    }

    public function useCertificate(string $certificate): void
    {
        $this->certificate = $certificate;
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
        match ($this->mode) {
            'hang' => null,
            'flood' => $this->flood($client),
            'smtp' => $this->dialog($client, "220 fixture ESMTP\r\n", [['EHLO', "250-fixture\r\n250-PIPELINING\r\n250 STARTTLS\r\n"], ['STARTTLS', "220 Ready to start TLS\r\n"]]),
            'smtp-without-starttls' => $this->dialog($client, "220 fixture ESMTP\r\n", [['EHLO', "250-fixture\r\n250 PIPELINING\r\n"], ['STARTTLS', "502 Not implemented\r\n"]]),
            'imap' => $this->dialog($client, "* OK fixture ready\r\n", [['CAPABILITY', "* CAPABILITY IMAP4rev1 STARTTLS LOGINDISABLED\r\n%s OK done\r\n"], ['STARTTLS', "%s OK Begin TLS\r\n"]]),
            'pop3' => $this->dialog($client, "+OK fixture ready\r\n", [['CAPA', "+OK\r\nUSER\r\nSTLS\r\n.\r\n"], ['STLS', "+OK Begin TLS\r\n"]]),
            default => null,
        };

        if ($this->mode === 'hang' || $this->mode === 'flood' || $this->mode === 'smtp-without-starttls') {
            if ($this->mode === 'hang') {
                // Keep the connection open without a word until the client gives up.
                $client->read();
            }

            return;
        }

        if ($client instanceof ResourceSocket && ($resource = $client->getResource()) !== null) {
            stream_context_set_option($resource, 'ssl', 'local_cert', $this->certificate);
            foreach ($this->sslOptions as $name => $value) {
                stream_context_set_option($resource, 'ssl', $name, $value);
            }
        }

        $client->setupTls();
        // Wait for the client to close, so it can finish reading the handshake.
        $client->read();
    }

    /**
     * @param  list<array{0: string, 1: string}>  $steps  Expected command and the reply; %s in IMAP replies is the tag.
     */
    private function dialog(Socket $client, string $greeting, array $steps): void
    {
        $client->write($greeting);
        $buffer = '';

        foreach ($steps as [$command, $reply]) {
            while (! str_contains($buffer, "\r\n")) {
                $chunk = $client->read();
                if ($chunk === null) {
                    return;
                }
                $buffer .= $chunk;
            }
            [$line, $buffer] = explode("\r\n", $buffer, 2);
            $parts = explode(' ', $line);
            $tag = $this->mode === 'imap' ? array_shift($parts) : '';
            if (strtoupper($parts[0]) !== $command) {
                $client->write("500 Unexpected command\r\n");

                return;
            }
            $client->write(sprintf($reply, $tag));
        }
    }

    private function flood(Socket $client): void
    {
        $client->write('220-'.str_repeat('x', 1024)."\r\n");
        for ($i = 0; $i < 100; $i++) {
            $client->write('220-'.str_repeat('x', 1024)."\r\n");
        }
    }
}
