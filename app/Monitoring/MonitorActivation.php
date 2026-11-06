<?php

namespace App\Monitoring;

use App\Enums\MonitorStatus;
use App\Models\Monitor;

/**
 * Switches a monitor on or off and keeps its runtime state in step.
 */
final class MonitorActivation
{
    /**
     * @return bool Whether the active flag changed.
     */
    public static function apply(Monitor $monitor, bool $active): bool
    {
        if ($monitor->active === $active) {
            return false;
        }

        $monitor->update(['active' => $active]);
        $monitor->state()->updateOrCreate([], [
            'status' => $active ? MonitorStatus::Pending : MonitorStatus::Paused,
            'status_since' => now(),
            'consecutive_failures' => 0,
        ]);

        return true;
    }
}
