<?php

namespace App\Monitoring\Engine;

use App\Events\MonitorStatusChanged;
use App\Jobs\BroadcastEvents;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatches events once the transaction that produced them has committed.
 *
 * Listeners run as usual, but the broadcasts of one dispatch are queued together as a single BroadcastEvents
 * job instead of one job per event (see broadcastsSeparately()).
 */
final class PostCommit
{
    private static bool $collecting = false;

    /**
     * A failing dispatch (for instance a queue that cannot be written) is logged and swallowed: the data is
     * committed already, and surfacing the error would make the caller redo work.
     *
     * @param  iterable<object>  $events
     */
    public static function dispatch(iterable $events): void
    {
        $broadcasts = [];
        self::$collecting = true;
        try {
            foreach ($events as $event) {
                try {
                    event($event);
                } catch (Throwable $e) {
                    self::failed($event, $e);

                    continue;
                }
                if ($event instanceof ShouldBroadcast) {
                    $broadcasts[] = $event;
                }
            }
        } finally {
            self::$collecting = false;
        }

        if ($broadcasts === []) {
            return;
        }
        try {
            BroadcastEvents::dispatch($broadcasts);
        } catch (Throwable $e) {
            self::failed($broadcasts[0], $e);
        }
    }

    /**
     * Queues jobs once the transaction that produced them has committed. Like events, a job that cannot be
     * queued is logged and swallowed.
     *
     * @param  iterable<object>  $jobs
     */
    public static function queue(iterable $jobs): void
    {
        foreach ($jobs as $job) {
            try {
                dispatch($job);
            } catch (Throwable $e) {
                Log::warning('Queueing a job failed after its data was committed.', [
                    'job' => $job::class,
                    'exception' => $e::class,
                ]);
            }
        }
    }

    /**
     * Whether a broadcast event dispatched now should queue its own broadcast job. False while dispatch()
     * runs, which queues the broadcasts of all its events in one job; the events' broadcastWhen() asks this.
     */
    public static function broadcastsSeparately(): bool
    {
        return ! self::$collecting;
    }

    private static function failed(object $event, Throwable $e): void
    {
        Log::warning('Dispatching a monitor event failed after its data was committed.', [
            'event' => $event::class,
            'monitor_id' => property_exists($event, 'monitorId') ? $event->monitorId : null,
            'workspace_id' => property_exists($event, 'workspaceId') ? $event->workspaceId : null,
            'exception' => $e::class,
        ]);
    }

    /**
     * Status change events for the transitions that actually changed a status.
     *
     * @param  list<Transition>  $transitions
     * @return list<MonitorStatusChanged>
     */
    public static function statusEvents(array $transitions, CarbonImmutable $at): array
    {
        $events = [];
        foreach ($transitions as $transition) {
            if ($transition->changed) {
                $events[] = new MonitorStatusChanged(
                    $transition->workspaceId, $transition->monitorId, $transition->from, $transition->effective,
                    $at, $transition->previousSince, $transition->message,
                );
            }
        }

        return $events;
    }
}
