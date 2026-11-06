<?php

namespace App\Monitoring\Engine;

use Carbon\CarbonImmutable;

/**
 * The missed deadline a push or cron outcome reports, carried along so the recorder can tell whether the
 * monitor reported in after it was evaluated.
 */
final readonly class Deadline
{
    /**
     * @param  CarbonImmutable  $at  The deadline that was missed.
     * @param  CarbonImmutable  $anchor  The moment the deadline counted from when it was evaluated.
     * @param  ?CarbonImmutable  $runStartedAt  For an overrun: the start of the run that exceeded its maximum runtime.
     */
    public function __construct(
        public CarbonImmutable $at,
        public CarbonImmutable $anchor,
        public ?CarbonImmutable $runStartedAt = null,
    ) {}
}
