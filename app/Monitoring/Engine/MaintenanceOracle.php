<?php

namespace App\Monitoring\Engine;

use Carbon\CarbonImmutable;

/**
 * Tells which monitors are inside a maintenance window. underMaintenance() answers from memory only.
 */
interface MaintenanceOracle
{
    /**
     * @param  list<int>  $monitorIds
     * @return list<int> The subset of the given ids under maintenance at the given moment.
     */
    public function underMaintenance(array $monitorIds, CarbonImmutable $at): array;

    /**
     * Refreshes whatever the oracle answers from. Never call it inside a transaction; it may read the database.
     */
    public function warm(): void;
}
