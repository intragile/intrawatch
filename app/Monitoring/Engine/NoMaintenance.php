<?php

namespace App\Monitoring\Engine;

use Carbon\CarbonImmutable;

/**
 * A maintenance oracle for installations without maintenance windows.
 */
final class NoMaintenance implements MaintenanceOracle
{
    public function underMaintenance(array $monitorIds, CarbonImmutable $at): array
    {
        return [];
    }

    public function warm(bool $force = false): void {}
}
