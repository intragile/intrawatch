<?php

namespace App\Alerting;

use RuntimeException;

/**
 * Signals that a delivery should be attempted again after the given number of seconds.
 */
final class RetryDelivery extends RuntimeException
{
    public function __construct(public readonly int $delay)
    {
        parent::__construct("Retry the delivery in {$delay} s.");
    }
}
