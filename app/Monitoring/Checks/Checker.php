<?php

namespace App\Monitoring\Checks;

use Amp\Cancellation;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\MonitorSpec;

/**
 * Runs one check of a monitor inside the event loop.
 *
 * Implementations must never throw and must never block: unexpected exceptions are turned into a down
 * outcome through CheckOutcome::fromThrowable(), which keeps the exception class name only.
 */
interface Checker
{
    public function check(MonitorSpec $spec, Cancellation $cancellation): CheckOutcome;
}
