<?php

namespace App\Alerting\Engine;

use App\Enums\MonitorStatus;

/**
 * The coarse state alerts are keyed on. Up and degraded-but-ignored both count as ok.
 */
enum AlertClass: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
    case Down = 'down';

    /**
     * Class of a monitor status, or null for statuses that carry no alerting signal
     * (pending, maintenance and paused).
     */
    public static function of(MonitorStatus $status, bool $notifyDegraded): ?self
    {
        return match ($status) {
            MonitorStatus::Up => self::Ok,
            MonitorStatus::Degraded => $notifyDegraded ? self::Degraded : self::Ok,
            MonitorStatus::Down => self::Down,
            MonitorStatus::Pending, MonitorStatus::Maintenance, MonitorStatus::Paused => null,
        };
    }
}
