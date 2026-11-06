<?php

namespace App\Monitoring\Worker;

use App\Monitoring\Engine\MonitorSpec;

/**
 * What changed in a shard since the previous poll.
 */
final readonly class ShardChanges
{
    /**
     * @param  array<int, MonitorSpec>  $added  Monitors that became active in the shard, keyed by id.
     * @param  array<int, MonitorSpec>  $changed  Monitors whose settings changed, keyed by id.
     * @param  list<int>  $removed  Monitors that were deleted, paused or left the shard.
     * @param  list<int>  $requested  Monitors with a new manual check request.
     */
    public function __construct(
        public array $added = [],
        public array $changed = [],
        public array $removed = [],
        public array $requested = [],
    ) {}
}
