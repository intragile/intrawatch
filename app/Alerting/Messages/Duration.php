<?php

namespace App\Alerting\Messages;

/**
 * Formats a number of seconds with its two largest non-zero units.
 */
final class Duration
{
    private const UNITS = [['d', 86400], ['h', 3600], ['min', 60], ['s', 1]];

    public static function human(int $seconds): string
    {
        $remaining = max(0, $seconds);
        $parts = [];

        foreach (self::UNITS as [$label, $size]) {
            $amount = intdiv($remaining, $size);
            $remaining -= $amount * $size;

            if ($amount > 0) {
                $parts[] = $amount.' '.$label;
            }

            if (count($parts) === 2) {
                break;
            }
        }

        return $parts === [] ? '0 s' : implode(' ', $parts);
    }
}
