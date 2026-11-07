<?php

namespace App\Enums;

/**
 * The public status vocabulary shown on status pages.
 */
enum DisplayStatus: string
{
    case Operational = 'operational';
    case Degraded = 'degraded';
    case PartialOutage = 'partial_outage';
    case MajorOutage = 'major_outage';
    case Maintenance = 'maintenance';
    case Unknown = 'unknown';

    public function severity(): int
    {
        return match ($this) {
            self::Operational => 0,
            self::Unknown => 1,
            self::Maintenance => 2,
            self::Degraded => 3,
            self::PartialOutage => 4,
            self::MajorOutage => 5,
        };
    }

    /**
     * The most severe of the given statuses; operational when none is given.
     */
    public static function worst(self ...$statuses): self
    {
        $worst = self::Operational;
        foreach ($statuses as $status) {
            if ($status->severity() > $worst->severity()) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
