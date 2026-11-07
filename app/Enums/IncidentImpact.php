<?php

namespace App\Enums;

enum IncidentImpact: string
{
    case None = 'none';
    case Minor = 'minor';
    case Major = 'major';
    case Critical = 'critical';

    public function displayStatus(): DisplayStatus
    {
        return match ($this) {
            self::None => DisplayStatus::Operational,
            self::Minor => DisplayStatus::Degraded,
            self::Major => DisplayStatus::PartialOutage,
            self::Critical => DisplayStatus::MajorOutage,
        };
    }
}
