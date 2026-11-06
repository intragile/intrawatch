<?php

namespace App\Monitoring\Worker;

use Amp\Cancellation;
use Carbon\CarbonImmutable;

use function Amp\delay;

/**
 * Wall-clock time and event-loop timers.
 */
final class SystemClock implements Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    public function sleep(float $seconds, ?Cancellation $cancellation = null): void
    {
        delay(max(0.0, $seconds), cancellation: $cancellation);
    }
}
