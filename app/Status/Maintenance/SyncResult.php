<?php

namespace App\Status\Maintenance;

use App\Jobs\EvaluateAlerts;
use App\Monitoring\Engine\PostCommit;

/**
 * What a maintenance sync did, plus the events and jobs that must be released once its transaction commits.
 */
final readonly class SyncResult
{
    /**
     * @param  list<object>  $events
     * @param  list<EvaluateAlerts>  $jobs
     */
    public function __construct(
        public int $requested = 0,
        public int $groups = 0,
        public array $events = [],
        public array $jobs = [],
    ) {}

    public function afterCommit(): void
    {
        PostCommit::dispatch($this->events);
        PostCommit::queue($this->jobs);
    }
}
