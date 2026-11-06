<?php

namespace App\Monitoring\Domain;

use App\Monitoring\Types\Hostname;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Extracts the expiry date and registrar from free-form WHOIS answers.
 *
 * WHOIS has no standard format, so only well-known labels and unambiguous date formats are accepted; anything
 * else yields no date rather than a wrong one.
 */
final class WhoisParser
{
    /** Expiry labels, most specific first. */
    private const EXPIRY_LABELS = [
        'Registry Expiry Date',
        'Registrar Registration Expiration Date',
        'Expiry Date',
        'Expiration Date',
        'paid-till',
        'Expires On',
        'expire',
    ];

    private const REGISTRAR_LABELS = ['Registrar', 'Sponsoring Registrar', 'registrar-name'];

    private const NOT_FOUND = [
        '/^\s*(?:Nincs tal[aá]lat\s*\/\s*)?No match\b/im',
        '/^\s*Status:\s*free\s*$/im',
        '/^\s*NOT FOUND\s*$/im',
        '/^\s*No Data Found\s*$/im',
        '/^\s*%?\s*No entries found\b/im',
        '/^\s*Domain not found\.?\s*$/im',
        '/^\s*This domain name has not been registered\.?\s*$/im',
    ];

    private const THROTTLED = '/\b(?:rate limit|limit exceeded|too many (?:requests|queries)|try again later|quota exceeded)\b/i';

    private const MAX_REGISTRAR_LENGTH = 255;

    public static function parse(string $text): WhoisRecord
    {
        $expiresAt = self::expiry($text);
        $notFound = false;
        $throttled = false;

        if ($expiresAt === null) {
            foreach (self::NOT_FOUND as $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    $notFound = true;
                    break;
                }
            }
            $throttled = ! $notFound && preg_match(self::THROTTLED, $text) === 1;
        }

        return new WhoisRecord($expiresAt, self::registrar($text), $notFound, $throttled);
    }

    /**
     * The WHOIS server an IANA answer refers to, from its `refer:` or `whois:` line.
     */
    public static function referral(string $text): ?string
    {
        if (preg_match('/^\s*(?:refer|whois):[ \t]*(\S+)[ \t]*$/im', $text, $m) !== 1) {
            return null;
        }

        $server = strtolower(rtrim($m[1], '.'));

        return Hostname::isHostname($server, 2) ? $server : null;
    }

    private static function expiry(string $text): ?CarbonImmutable
    {
        foreach (self::EXPIRY_LABELS as $label) {
            if (preg_match('/^[ \t]*'.preg_quote($label, '/').'[ \t]*:[ \t]*(\S[^\r\n]*?)[ \t]*\r?$/im', $text, $m) === 1) {
                $date = self::date($m[1]);
                if ($date !== null) {
                    return $date;
                }
            }
        }

        return null;
    }

    /**
     * Accepts ISO 8601 dates and date-times, `YYYY.MM.DD`, `DD-Mon-YYYY` and `DD.MM.YYYY`. Dates without a time
     * are read as midnight UTC. Slash-separated day-first or month-first dates are ambiguous and ignored.
     */
    private static function date(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        try {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})(?:\.\d+)?\s*(Z|UTC|[+-]\d{2}:?\d{2})?$/i', $value, $m) === 1) {
                $zone = strtoupper($m[7] ?? '');
                $zone = $zone === '' || $zone === 'Z' || $zone === 'UTC' ? 'UTC' : $zone;

                return self::valid(CarbonImmutable::createFromFormat('Y-m-d H:i:s', "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}", $zone));
            }
            if (preg_match('/^(\d{4})[-.\/](\d{2})[-.\/](\d{2})$/', $value, $m) === 1) {
                return self::valid(CarbonImmutable::createFromFormat('!Y-m-d', "{$m[1]}-{$m[2]}-{$m[3]}", 'UTC'));
            }
            if (preg_match('/^(\d{1,2})-([A-Za-z]{3})-(\d{4})$/', $value, $m) === 1) {
                return self::valid(CarbonImmutable::createFromFormat('!d-M-Y', "{$m[1]}-{$m[2]}-{$m[3]}", 'UTC'));
            }
            if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $m) === 1) {
                return self::valid(CarbonImmutable::createFromFormat('!d.m.Y', $value, 'UTC'));
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private static function valid(mixed $date): ?CarbonImmutable
    {
        if (! $date instanceof CarbonImmutable) {
            return null;
        }

        $errors = CarbonImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return DomainExpiry::storable($date);
    }

    private static function registrar(string $text): ?string
    {
        $lines = preg_split('/\r?\n/', $text) ?: [];

        foreach (self::REGISTRAR_LABELS as $label) {
            foreach ($lines as $index => $line) {
                if (preg_match('/^[ \t]*'.preg_quote($label, '/').'[ \t]*:[ \t]*(.*?)[ \t]*$/i', $line, $m) !== 1) {
                    continue;
                }

                // Some registries put the value on the following, indented line.
                $value = $m[1] !== '' ? $m[1] : trim($lines[$index + 1] ?? '');
                if ($value !== '' && ! str_contains($value, ':')) {
                    return mb_substr(mb_scrub($value, 'UTF-8'), 0, self::MAX_REGISTRAR_LENGTH);
                }
            }
        }

        return null;
    }
}
