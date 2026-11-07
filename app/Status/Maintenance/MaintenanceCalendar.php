<?php

namespace App\Status\Maintenance;

use Carbon\CarbonImmutable;

/**
 * An immutable, in-memory view of the merged maintenance windows of every monitor over a fixed horizon.
 *
 * Windows of one monitor are sorted by start and never overlap or touch, so a point lookup is a binary
 * search. An open-ended window has no end and stays open-ended.
 */
final class MaintenanceCalendar
{
    /** @var list<int> Distinct window starts and ends as Unix timestamps, ascending. */
    private readonly array $boundaries;

    /** @var array<int, list<int>> Monitor ids by boundary timestamp. */
    private readonly array $monitorsByBoundary;

    /**
     * @param  array<int, list<Window>>  $byMonitor  Merged, sorted windows by monitor id.
     */
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        private readonly array $byMonitor,
    ) {
        $byBoundary = [];
        foreach ($byMonitor as $monitorId => $windows) {
            foreach ($windows as $window) {
                $byBoundary[$window->start->getTimestamp()][$monitorId] = $monitorId;
                if ($window->end !== null) {
                    $byBoundary[$window->end->getTimestamp()][$monitorId] = $monitorId;
                }
            }
        }
        ksort($byBoundary);
        $this->boundaries = array_keys($byBoundary);
        $this->monitorsByBoundary = array_map(array_values(...), $byBoundary);
    }

    public static function empty(): self
    {
        $now = CarbonImmutable::now();

        return new self($now, $now, []);
    }

    /**
     * Whether the moment lies inside the horizon the calendar was built for, `[from, to)`.
     */
    public function covers(CarbonImmutable $at): bool
    {
        return $at->gte($this->from) && $at->lt($this->to);
    }

    /**
     * @param  list<int>  $monitorIds
     * @return list<int> The given ids that are inside a window at the given moment.
     */
    public function underMaintenance(array $monitorIds, CarbonImmutable $at): array
    {
        $timestamp = $at->getTimestamp();
        $result = [];
        foreach ($monitorIds as $id) {
            $windows = $this->byMonitor[$id] ?? null;
            if ($windows !== null && $this->windowAt($windows, $timestamp)) {
                $result[] = $id;
            }
        }

        return $result;
    }

    /**
     * The earliest window start or end strictly after the given moment.
     */
    public function nextBoundaryAfter(CarbonImmutable $at): ?CarbonImmutable
    {
        $index = $this->firstBoundaryAfter($at->getTimestamp());

        return $index < count($this->boundaries) ? CarbonImmutable::createFromTimestampUTC($this->boundaries[$index]) : null;
    }

    /**
     * @return list<int> Monitors with a window start or end in `(from, to]`.
     */
    public function monitorsWithBoundaryIn(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $until = $to->getTimestamp();
        $ids = [];
        for ($i = $this->firstBoundaryAfter($from->getTimestamp()); $i < count($this->boundaries) && $this->boundaries[$i] <= $until; $i++) {
            foreach ($this->monitorsByBoundary[$this->boundaries[$i]] as $id) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param  list<Window>  $windows
     */
    private function windowAt(array $windows, int $timestamp): bool
    {
        // Last window whose start is not after the moment.
        $low = 0;
        $high = count($windows) - 1;
        $found = -1;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            if ($windows[$middle]->start->getTimestamp() <= $timestamp) {
                $found = $middle;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        if ($found < 0) {
            return false;
        }
        $end = $windows[$found]->end;

        return $end === null || $end->getTimestamp() > $timestamp;
    }

    private function firstBoundaryAfter(int $timestamp): int
    {
        $low = 0;
        $high = count($this->boundaries);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($this->boundaries[$middle] > $timestamp) {
                $high = $middle;
            } else {
                $low = $middle + 1;
            }
        }

        return $low;
    }
}
