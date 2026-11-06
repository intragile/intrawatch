<?php

namespace App\Enums;

enum MonitorStatus: string
{
    case Up = 'up';
    case Down = 'down';
    case Degraded = 'degraded';
    case Maintenance = 'maintenance';
    case Pending = 'pending';
    case Paused = 'paused';

    /**
     * Whether the monitored target counts as reachable for uptime purposes.
     */
    public function isAvailable(): bool
    {
        return match ($this) {
            self::Up, self::Degraded, self::Pending => true,
            self::Down, self::Maintenance, self::Paused => false,
        };
    }
}
