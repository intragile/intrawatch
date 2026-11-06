<?php

namespace App\Monitoring\Checks;

/**
 * Maps the days left until an expiry date to a warning band, so crossing a threshold changes a watched value.
 */
final class ExpiryThresholds
{
    /** Thresholds used when a monitor's config holds none. */
    public const DEFAULT = [30, 14, 7, 1];

    /** Days left below which an otherwise healthy check is reported as degraded, unless configured otherwise. */
    public const DEGRADED_BELOW_DAYS = 7;

    /**
     * The smallest threshold that is at least $daysLeft, as a string; 'ok' above every threshold and
     * 'expired' below zero.
     *
     * @param  list<int>  $thresholds  Days, in any order.
     */
    public static function band(int $daysLeft, array $thresholds): string
    {
        if ($daysLeft < 0) {
            return 'expired';
        }

        $matching = array_filter($thresholds, static fn (int $threshold): bool => $threshold >= $daysLeft);

        return $matching === [] ? 'ok' : (string) min($matching);
    }

    /**
     * The configured thresholds, or the defaults when the config holds none.
     *
     * @return list<int>
     */
    public static function fromConfig(mixed $value): array
    {
        $thresholds = is_array($value) ? array_values(array_filter($value, is_int(...))) : [];

        return $thresholds === [] ? self::DEFAULT : $thresholds;
    }

    /**
     * A short message such as "Certificate expires in 3 days", or "Certificate expired" once past.
     */
    public static function expiresIn(string $subject, int $daysLeft): string
    {
        return match (true) {
            $daysLeft < 0 => "{$subject} expired",
            $daysLeft === 0 => "{$subject} expires today",
            $daysLeft === 1 => "{$subject} expires in 1 day",
            default => "{$subject} expires in {$daysLeft} days",
        };
    }
}
