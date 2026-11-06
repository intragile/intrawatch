<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A session-based endpoint was called without a session, so a login would silently not stick.
 * Sessions are only started for requests whose Origin/Referer is a stateful domain.
 */
class SessionUnavailable extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'Session unavailable: the request origin is not a stateful domain. Send an Origin header that '
            .'matches APP_URL, or add the host to SANCTUM_STATEFUL_DOMAINS.',
        );
    }
}
