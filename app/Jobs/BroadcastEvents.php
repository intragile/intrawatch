<?php

namespace App\Jobs;

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Broadcasts a list of events from one queued job instead of one job per event.
 *
 * A flush of the check worker can change thousands of statuses at once; queueing each broadcast on its own
 * would mean one queue insert per event inside the worker's event loop. Live updates are best effort: an
 * event that cannot be broadcast is logged and skipped, so a retry never repeats the ones already sent.
 */
class BroadcastEvents implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /**
     * @param  list<ShouldBroadcast>  $events
     */
    public function __construct(public readonly array $events) {}

    public function handle(BroadcastingFactory $broadcaster): void
    {
        foreach ($this->events as $event) {
            try {
                (new BroadcastEvent($event))->handle($broadcaster);
            } catch (Throwable $e) {
                Log::warning('Broadcasting a monitor event failed.', ['event' => $event::class, 'exception' => $e::class]);
            }
        }
    }
}
