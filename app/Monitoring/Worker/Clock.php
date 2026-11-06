<?php

namespace App\Monitoring\Worker;

use Amp\Cancellation;
use Amp\CancelledException;
use Carbon\CarbonImmutable;

/**
 * The time source of the worker loop. Everything that waits inside the event loop waits through this, so
 * tests can drive the loop with virtual time.
 */
interface Clock
{
    public function now(): CarbonImmutable;

    /**
     * Suspends the current fiber for the given number of seconds without blocking the event loop.
     *
     * @throws CancelledException When the cancellation is requested before the time is up.
     */
    public function sleep(float $seconds, ?Cancellation $cancellation = null): void;
}
