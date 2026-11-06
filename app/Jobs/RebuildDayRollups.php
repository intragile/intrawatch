<?php

namespace App\Jobs;

use App\Models\Workspace;
use App\Monitoring\Engine\RollupBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Recomputes a workspace's day rollups after its timezone changed.
 */
class RebuildDayRollups implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly int $workspaceId) {}

    public function handle(RollupBuilder $builder): void
    {
        $workspace = Workspace::query()->find($this->workspaceId);
        if ($workspace instanceof Workspace) {
            $builder->rebuildDays($workspace);
        }
    }
}
