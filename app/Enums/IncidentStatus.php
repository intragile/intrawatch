<?php

namespace App\Enums;

enum IncidentStatus: string
{
    case Investigating = 'investigating';
    case Identified = 'identified';
    case Monitoring = 'monitoring';
    case Resolved = 'resolved';

    public function isOpen(): bool
    {
        return $this !== self::Resolved;
    }
}
