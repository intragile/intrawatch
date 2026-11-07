<?php

namespace App\Listeners;

use App\Events\MonitorStatusChanged;
use App\Status\Pages\PageStamps;

/**
 * Moves the workspace stamp when a monitor's status changes, so cached public pages re-render. A burst of
 * changes inside one second costs one file write per workspace.
 */
final class StampStatusPages
{
    /** @var array<int, int> */
    private static array $stamped = [];

    public function __construct(private readonly PageStamps $stamps) {}

    public function handle(MonitorStatusChanged $event): void
    {
        $second = time();
        if ((self::$stamped[$event->workspaceId] ?? null) === $second) {
            return;
        }
        self::$stamped[$event->workspaceId] = $second;

        $this->stamps->bumpWorkspace($event->workspaceId);
    }

    public static function reset(): void
    {
        self::$stamped = [];
    }
}
