<?php

namespace App\Status\Pages;

use App\Enums\DisplayStatus;

/**
 * One workspace-local day of a component's history.
 */
final readonly class DayBar
{
    private const MINUTES_PER_DAY = 1440;

    /**
     * @param  string  $date  Local date, `Y-m-d`.
     * @param  int  $downMinutesApprox  Share of down samples among the counted samples, scaled to a 1 440-minute day.
     */
    public function __construct(
        public string $date,
        public DisplayStatus $status,
        public ?float $uptimePercent,
        public int $downMinutesApprox,
    ) {}

    /**
     * A bar from the sample counts of a day rollup; all zero for a day without a rollup row.
     */
    public static function fromCounts(string $date, int $up, int $degraded, int $down, int $pending, int $maintenance): self
    {
        $counted = $up + $degraded + $down;

        return new self(
            $date,
            DisplayStatusResolver::day($up, $degraded, $down, $maintenance),
            DisplayStatusResolver::uptimePercent($up + $degraded, $down),
            $counted === 0 ? 0 : (int) round($down / $counted * self::MINUTES_PER_DAY),
        );
    }
}
