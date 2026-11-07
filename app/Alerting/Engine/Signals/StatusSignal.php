<?php

namespace App\Alerting\Engine\Signals;

use App\Enums\MonitorStatus;
use Carbon\CarbonImmutable;

/**
 * A change of a monitor's effective status, as decided by the check engine.
 */
final readonly class StatusSignal
{
    /**
     * @param  CarbonImmutable  $previousSince  When the monitor entered $from.
     */
    public function __construct(
        public MonitorStatus $from,
        public MonitorStatus $to,
        public CarbonImmutable $at,
        public CarbonImmutable $previousSince,
        public string $message,
    ) {}
}
