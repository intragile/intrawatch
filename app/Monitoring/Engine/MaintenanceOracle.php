<?php

namespace App\Monitoring\Engine;

use Carbon\CarbonImmutable;

/**
 * Tells which monitors are inside a maintenance window.
 */
interface MaintenanceOracle
{
    /**
     * @param  list<int>  $monitorIds
     * @return list<int> The subset of the given ids under maintenance at the given moment.
     */
    public function underMaintenance(array $monitorIds, CarbonImmutable $at): array;
}
