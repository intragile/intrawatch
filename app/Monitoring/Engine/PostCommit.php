<?php

namespace App\Monitoring\Engine;

use App\Events\MonitorStatusChanged;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatches events once the transaction that produced them has committed.
 */
final class PostCommit
{
    /**
     * A failing dispatch (for instance a queue that cannot be written) is logged and swallowed: the data is
     * committed already, and surfacing the error would make the caller redo work.
     *
     * @param  iterable<object>  $events
     */
    public static function dispatch(iterable $events): void
    {
        foreach ($events as $event) {
            try {
                event($event);
            } catch (Throwable $e) {
                Log::warning('Dispatching a monitor event failed after its data was committed.', [
                    'event' => $event::class,
                    'monitor_id' => property_exists($event, 'monitorId') ? $event->monitorId : null,
                    'workspace_id' => property_exists($event, 'workspaceId') ? $event->workspaceId : null,
                    'exception' => $e::class,
                ]);
            }
        }
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
