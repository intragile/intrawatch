<?php

namespace App\Enums;

enum AlertKind: string
{
    case Down = 'down';
    case Up = 'up';
    case Degraded = 'degraded';
    case StillDown = 'still_down';
    case Flapping = 'flapping';
    case Stable = 'stable';
    case Expiring = 'expiring';
    case Renewed = 'renewed';
    case Changed = 'changed';
    case Test = 'test';

    public function label(): string
    {
        return strtoupper(str_replace('_', ' ', $this->value));
    }

    /**
     * Provider-neutral severity: critical, warning, ok or info.
     */
    public function severity(): string
    {
        return match ($this) {
            self::Down, self::StillDown => 'critical',
            self::Degraded, self::Flapping, self::Expiring, self::Changed => 'warning',
            self::Up, self::Stable, self::Renewed => 'ok',
            self::Test => 'info',
        };
    }
}
