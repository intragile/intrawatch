<?php

namespace App\Status\Maintenance;

use App\Models\Maintenance;

/**
 * A maintenance definition as it stood at one moment: its schedule and the monitors it targets. Captured before
 * and after a write so the sync can tell which monitors changed state.
 */
final readonly class MaintenanceState
{
    /**
     * @param  list<int>  $targetIds
     */
    public function __construct(
        public MaintenanceSchedule $schedule,
        public array $targetIds,
    ) {}

    /**
     * @param  list<int>  $targetIds
     */
    public static function of(Maintenance $maintenance, string $workspaceTimezone, array $targetIds): self
    {
        return new self(MaintenanceSchedule::fromModel($maintenance, $workspaceTimezone), $targetIds);
    }
}
