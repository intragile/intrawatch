<?php

namespace App\Status\Maintenance;

use App\Jobs\EvaluateAlerts;
use App\Monitoring\Engine\PostCommit;
use Closure;
use Throwable;

/**
 * What a maintenance sync did, plus the events and jobs that must be released once its transaction commits.
 */
final readonly class SyncResult
{
    /**
     * @param  list<object>  $events
     * @param  list<EvaluateAlerts>  $jobs
     * @param  ?Closure(): void  $then  Work that needs the committed state, such as results recorded in a
     *                                  transaction of their own.
     */
    public function __construct(
        public int $requested = 0,
        public int $groups = 0,
        public array $events = [],
        public array $jobs = [],
        public ?Closure $then = null,
    ) {}

    public function afterCommit(): void
    {
        PostCommit::dispatch($this->events);
        PostCommit::queue($this->jobs);
        if ($this->then === null) {
            return;
        }
        try {
            ($this->then)();
        } catch (Throwable $e) {
            // The write is committed; a monitor left out here keeps its status until its next recorded result.
            report($e);
        }
    }
}
