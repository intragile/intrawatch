<?php

namespace App\Status\Maintenance;

use Carbon\CarbonImmutable;

/**
 * A concrete maintenance period in UTC: start inclusive, end exclusive, no end meaning open-ended.
 */
final readonly class Window
{
    public function __construct(
        public CarbonImmutable $start,
        public ?CarbonImmutable $end,
    ) {}

    public function contains(CarbonImmutable $at): bool
    {
        return $this->start->lte($at) && ($this->end === null || $this->end->gt($at));
    }

    /**
     * Whether the window shares any instant with the half-open range [$from, $to).
     */
    public function overlaps(CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return $this->start->lt($to) && ($this->end === null || $this->end->gt($from));
    }
}
