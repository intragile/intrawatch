<?php

namespace App\Monitoring\Net;

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\Socket\Socket;

/**
 * The plaintext SMTP, IMAP and POP3 dialogs that upgrade a connection to TLS.
 *
 * Every read is bounded: a line may be at most MAX_LINE bytes and the whole dialog at most MAX_DIALOG bytes, and
 * every read observes the caller's cancellation, so a server that floods or stalls cannot hold the check. A
 * server that does not advertise the extension fails with "STARTTLS not offered"; any other unexpected reply,
 * and data sent ahead of the TLS handshake, fails with "STARTTLS failed".
 */
final class StartTls
{
    public const MAX_LINE = 4096;

    public const MAX_DIALOG = 16384;

    /** Lines of a single reply; bounds multi-line replies independently of their size. */
    private const MAX_LINES = 100;

    private string $buffer = '';

    private int $received = 0;

    private function __construct(private readonly Socket $socket, private readonly Cancellation $cancellation) {}

    /**
     * Runs the dialog for the protocol; 'none' does nothing.
     *
     * @throws TlsFailure When the server does not offer or refuses the upgrade.
     */
    public static function negotiate(Socket $socket, string $protocol, Cancellation $cancellation): void
    {
        if ($protocol === 'none') {
            return;
        }

        $dialog = new self($socket, $cancellation);
        match ($protocol) {
            'smtp' => $dialog->smtp(),
            'imap' => $dialog->imap(),
            'pop3' => $dialog->pop3(),
            default => throw TlsFailure::startTlsFailed(),
        };

        // Anything the server sent beyond the final reply would be read as TLS data, or injected before it.
        if ($dialog->buffer !== '') {
            throw TlsFailure::startTlsFailed();
        }
    }

    private function smtp(): void
    {
        $this->expectSmtp('220');
        $this->send("EHLO intrawatch.invalid\r\n");
        $capabilities = $this->expectSmtp('250');

        $offered = false;
        foreach ($capabilities as $line) {
            if (strtoupper(trim(substr($line, 4))) === 'STARTTLS') {
                $offered = true;
            }
        }
        if (! $offered) {
            throw TlsFailure::startTlsNotOffered();
        }

        $this->send("STARTTLS\r\n");
        $this->expectSmtp('220');
    }

    private function imap(): void
    {
        $greeting = $this->line();
        if (! str_starts_with(strtoupper($greeting), '* OK')) {
            throw TlsFailure::startTlsFailed();
        }

        $this->send("a1 CAPABILITY\r\n");
        $offered = false;
        for ($i = 0; ; $i++) {
            $line = $this->line();
            $upper = strtoupper($line);
            if (str_starts_with($upper, '* CAPABILITY ') && in_array('STARTTLS', explode(' ', $upper), true)) {
                $offered = true;
            }
            if (str_starts_with($upper, 'A1 ')) {
                if (! str_starts_with($upper, 'A1 OK')) {
                    throw TlsFailure::startTlsFailed();
                }
                break;
            }
            if ($i >= self::MAX_LINES || ! str_starts_with($line, '* ')) {
                throw TlsFailure::startTlsFailed();
            }
        }
        if (! $offered) {
            throw TlsFailure::startTlsNotOffered();
        }

        $this->send("a2 STARTTLS\r\n");
        if (! str_starts_with(strtoupper($this->line()), 'A2 OK')) {
            throw TlsFailure::startTlsFailed();
        }
    }

    private function pop3(): void
    {
        if (! str_starts_with($this->line(), '+OK')) {
            throw TlsFailure::startTlsFailed();
        }

        $this->send("CAPA\r\n");
        if (! str_starts_with($this->line(), '+OK')) {
            // Servers without CAPA cannot advertise STLS.
            throw TlsFailure::startTlsNotOffered();
        }

        $offered = false;
        for ($i = 0; ($line = $this->line()) !== '.'; $i++) {
            if ($i >= self::MAX_LINES) {
                throw TlsFailure::startTlsFailed();
            }
            if (strtoupper(trim($line)) === 'STLS') {
                $offered = true;
            }
        }
        if (! $offered) {
            throw TlsFailure::startTlsNotOffered();
        }

        $this->send("STLS\r\n");
        if (! str_starts_with($this->line(), '+OK')) {
            throw TlsFailure::startTlsFailed();
        }
    }

    /**
     * Reads one SMTP reply, which may span several lines, and requires the given code.
     *
     * @return list<string>
     */
    private function expectSmtp(string $code): array
    {
        $lines = [];

        while (true) {
            $line = $this->line();
            if (strlen($line) < 3 || ! str_starts_with($line, $code) || (strlen($line) > 3 && ! in_array($line[3], [' ', '-'], true))) {
                throw TlsFailure::startTlsFailed();
            }
            $lines[] = $line;

            if (strlen($line) === 3 || $line[3] === ' ') {
                return $lines;
            }
            if (count($lines) >= self::MAX_LINES) {
                throw TlsFailure::startTlsFailed();
            }
        }
    }

    private function send(string $command): void
    {
        try {
            $this->socket->write($command);
        } catch (StreamException) {
            throw TlsFailure::startTlsFailed();
        }
    }

    /**
     * Reads one CRLF- or LF-terminated line, without the terminator.
     */
    private function line(): string
    {
        while (($position = strpos($this->buffer, "\n")) === false) {
            if (strlen($this->buffer) >= self::MAX_LINE) {
                throw TlsFailure::startTlsFailed();
            }

            try {
                $chunk = $this->socket->read($this->cancellation, self::MAX_LINE);
            } catch (StreamException) {
                throw TlsFailure::startTlsFailed();
            }
            if ($chunk === null) {
                throw TlsFailure::startTlsFailed();
            }

            $this->received += strlen($chunk);
            if ($this->received > self::MAX_DIALOG) {
                throw TlsFailure::startTlsFailed();
            }
            $this->buffer .= $chunk;
        }

        if ($position > self::MAX_LINE) {
            throw TlsFailure::startTlsFailed();
        }

        $line = substr($this->buffer, 0, $position);
        $this->buffer = substr($this->buffer, $position + 1);

        return rtrim($line, "\r");
    }
}
