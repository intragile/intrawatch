<?php

namespace App\Monitoring\Net;

use RuntimeException;

/**
 * A TLS inspection that could not read a certificate. The message is a fixed, user-facing phrase that never
 * contains host names, addresses or server output.
 */
final class TlsFailure extends RuntimeException
{
    public static function handshake(): self
    {
        return new self('TLS handshake failed');
    }

    public static function startTlsNotOffered(): self
    {
        return new self('STARTTLS not offered');
    }

    public static function startTlsFailed(): self
    {
        return new self('STARTTLS failed');
    }
}
