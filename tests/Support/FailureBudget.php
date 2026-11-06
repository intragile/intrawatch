<?php

namespace Tests\Support;

/**
 * How many more times a simulated failure should happen.
 */
final class FailureBudget
{
    public function __construct(public int $remaining) {}
}
