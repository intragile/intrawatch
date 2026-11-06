<?php

namespace App\Jobs;

use App\Models\Workspace;
use App\Monitoring\Engine\RollupBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Recomputes a workspace's day rollups after its timezone changed.
 *
 * One job per workspace is queued at a time. The rebuild replaces one local day per transaction, so a run that is
 * killed leaves consistent rows behind and the next timezone change (or a manual dispatch) finishes the work. A
 * timezone that changes again while the job runs is picked up before the job ends.
 */
class RebuildDayRollups implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    private const MAX_PASSES = 3;

    public int $timeout = 900;

    public int $tries = 1;

    /** Seconds the uniqueness lock lives if the job never finishes. */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $workspaceId) {}

    public function uniqueId(): string
    {
        return (string) $this->workspaceId;
    }

    public function handle(RollupBuilder $builder): void
    {
        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $workspace = Workspace::query()->find($this->workspaceId);
            if (! $workspace instanceof Workspace) {
                return;
            }

            $builder->rebuildDays($workspace);

            if (Workspace::query()->whereKey($this->workspaceId)->value('timezone') === $workspace->timezone) {
                return;
            }
        }
    }
}
