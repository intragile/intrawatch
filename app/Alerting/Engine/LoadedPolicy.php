<?php

namespace App\Alerting\Engine;

/**
 * A monitor's alert policy together with the monitor facts the evaluator routes by.
 */
final readonly class LoadedPolicy
{
    public function __construct(
        public int $monitorId,
        public int $workspaceId,
        public ?int $parentId,
        public bool $active,
        public AlertPolicy $policy,
    ) {}
}
