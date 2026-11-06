<?php

namespace Tests\Support;

use App\Monitoring\Engine\MaintenanceOracle;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * A maintenance oracle that fails whenever one particular monitor is part of the question, which makes
 * the recorder reject exactly the batches that contain it.
 */
final class PoisonMaintenance implements MaintenanceOracle
{
    public function __construct(private readonly int $poisonId) {}

    public function underMaintenance(array $monitorIds, CarbonImmutable $at): array
    {
        if (in_array($this->poisonId, $monitorIds, true)) {
            throw new RuntimeException('Cannot evaluate maintenance.');
        }

        return [];
    }
}
