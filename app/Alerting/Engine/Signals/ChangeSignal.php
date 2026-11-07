<?php

namespace App\Alerting\Engine\Signals;

use Carbon\CarbonImmutable;

/**
 * All watched-value changes of one monitor observed by one check.
 */
final readonly class ChangeSignal
{
    /**
     * @param  list<array{key: string, old: ?string, new: string}>  $changes
     */
    public function __construct(
        public CarbonImmutable $at,
        public array $changes,
    ) {}
}
