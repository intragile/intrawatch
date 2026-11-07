<?php

namespace App\Alerting\Engine;

use App\Enums\AlertKind;
use App\Enums\MonitorStatus;

/**
 * An alert the machine decided on, before it is stored and routed to channels.
 */
final readonly class PlannedAlert
{
    /**
     * @param  list<array{key: string, old: ?string, new: string}>  $changes  Watched values behind a change alert.
     * @param  bool  $suppressed  Recorded only: an ancestor group is down and already alerting.
     */
    public function __construct(
        public AlertKind $kind,
        public ?MonitorStatus $status,
        public ?MonitorStatus $previousStatus,
        public string $message,
        public ?int $durationSeconds,
        public array $changes,
        public bool $suppressed,
    ) {}
}
