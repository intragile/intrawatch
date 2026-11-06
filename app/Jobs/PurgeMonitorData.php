<?php

namespace App\Jobs;

use App\Monitoring\Engine\ChunkedDeleter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Removes the history that belonged to a deleted monitor, in short chunks.
 */
class PurgeMonitorData implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $monitorId) {}

    public function handle(): void
    {
        $deleter = app(ChunkedDeleter::class);
        $filter = fn (Builder $q) => $q->where('monitor_id', $this->monitorId);

        foreach (['check_results', 'check_rollups', 'monitor_events'] as $table) {
            $deleter->byIdChunks($table, $filter);
        }
    }
}
