<?php

namespace App\Alerting\Engine\Signals;

use Carbon\CarbonImmutable;

/**
 * A time-driven re-evaluation: degraded hold, reminders, flap end and suppression catch-up.
 */
final readonly class TickSignal
{
    public function __construct(
        public CarbonImmutable $now,
    ) {}
}
