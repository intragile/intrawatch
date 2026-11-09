<?php

namespace App\Monitoring\Engine;

use App\Enums\MonitorStatus;
use Carbon\CarbonImmutable;

/**
 * The result of applying one outcome to a monitor's state.
 */
final readonly class Transition
{
    /**
     * @param  MonitorStatus  $from  The status before the outcome was applied.
     * @param  CarbonImmutable  $previousSince  Since when the previous status had held.
     * @param  string  $message  The message stored with the result, after thresholds and inversion.
     * @param  int  $nextDelaySeconds  Seconds until the monitor should be checked again.
     * @param  CarbonImmutable|null  $checkedAt  The checked_at of the result row written with it, when the writer stamped
     *                                           the row itself (group evaluations).
     */
    public function __construct(
        public int $monitorId,
        public int $workspaceId,
        public MonitorStatus $from,
        public MonitorStatus $effective,
        public int $consecutiveFailures,
        public bool $changed,
        public int $nextDelaySeconds,
        public string $message,
        public CarbonImmutable $previousSince,
        public ?CarbonImmutable $checkedAt = null,
    ) {}
}
