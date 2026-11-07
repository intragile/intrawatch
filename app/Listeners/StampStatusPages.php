<?php

namespace App\Listeners;

use App\Events\MonitorStatusChanged;
use App\Status\Pages\PageStamps;
use Throwable;

/**
 * Moves the workspace stamp when a monitor's status changes, so cached public pages re-render. A burst of
 * changes inside one second costs one file write per workspace.
 *
 * A failure is reported and swallowed: an exception here would stop the event's later listeners and its
 * broadcast, and the page TTL bounds how long a missed stamp leaves a page stale.
 */
final class StampStatusPages
{
    /**
     * Last wall-clock second a workspace was stamped in this process. A change that lands in the same second as
     * an earlier bump is not stamped again, so a page cached between the two can stay up to one HTML TTL stale
     * (bounded, since the cache entry expires on its own) in exchange for at most one file write per second.
     *
     * @var array<int, int>
     */
    private static array $stamped = [];

    public function __construct(private readonly PageStamps $stamps) {}

    public function handle(MonitorStatusChanged $event): void
    {
        $second = time();
        if ((self::$stamped[$event->workspaceId] ?? null) === $second) {
            return;
        }
        self::$stamped[$event->workspaceId] = $second;

        try {
            $this->stamps->bumpWorkspace($event->workspaceId);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function reset(): void
    {
        self::$stamped = [];
    }
}
