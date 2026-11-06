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
        $state = [
            'status' => $active ? MonitorStatus::Pending : MonitorStatus::Paused,
            'status_since' => now(),
            'consecutive_failures' => 0,
        ];
        if ($active) {
            // A resumed push or cron monitor gets a full deadline from now, and a run that was in progress
            // when it was paused is forgotten, so resuming never yields an instant down.
            $state += ['deadline_anchor_at' => now(), 'run_started_at' => null];
        }
        $monitor->state()->updateOrCreate([], $state);

        return true;
    }
}
