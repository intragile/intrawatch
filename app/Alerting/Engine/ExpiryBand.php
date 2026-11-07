<?php

namespace App\Alerting\Engine;

use InvalidArgumentException;

/**
 * Orders and describes the expiry bands produced by the certificate and domain checks.
 */
final readonly class ExpiryBand
{
    /**
     * Lower is more urgent: `expired` ranks -1, a numeric band its day count, `ok` (and anything unknown) last.
     */
    public static function rank(string $band): int
    {
        return match (true) {
            $band === 'expired' => -1,
            ctype_digit($band) => (int) $band,
            default => PHP_INT_MAX,
        };
    }

    /**
     * A short description of entering a warning band, such as "Certificate expires within 14 days".
     *
     * @throws InvalidArgumentException for `ok` or an unknown band, which no expiry warning enters.
     */
    public static function message(string $key, string $band): string
    {
        $subject = self::subject($key);

        return match (true) {
            $band === 'expired' => "{$subject} expired",
            ctype_digit($band) => $band === '1' ? "{$subject} expires within 1 day" : "{$subject} expires within {$band} days",
            default => throw new InvalidArgumentException("Expiry band \"{$band}\" is not a warning band."),
        };
    }

    /**
     * A short description of a band improving, such as "Certificate renewed".
     */
    public static function renewedMessage(string $key): string
    {
        return self::subject($key).' renewed';
    }

    private static function subject(string $key): string
    {
        $prefix = explode('.', $key, 2)[0];

        return match ($prefix) {
            'tls' => 'Certificate',
            'domain' => 'Domain',
            default => ucfirst($prefix),
        };
    }
}
