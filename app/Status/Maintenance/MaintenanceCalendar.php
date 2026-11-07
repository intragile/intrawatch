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
    /** @var ?list<int> Distinct window starts and ends as Unix timestamps, ascending. */
    private ?array $boundaries = null;

    /** @var array<int, list<int>> Monitor ids by boundary timestamp. */
    private array $monitorsByBoundary = [];

    /**
     * @param  array<int, list<Window>>  $byMonitor  Merged, sorted windows by monitor id.
     */
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        private readonly array $byMonitor,
    ) {}

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
        $boundaries = $this->boundaries();
        $index = $this->firstBoundaryAfter($at->getTimestamp());

        return $index < count($boundaries) ? CarbonImmutable::createFromTimestampUTC($boundaries[$index]) : null;
    }

    /**
     * @return list<int> Monitors with a window start or end in `(from, to]`.
     */
    public function monitorsWithBoundaryIn(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $boundaries = $this->boundaries();
        $until = $to->getTimestamp();
        $ids = [];
        for ($i = $this->firstBoundaryAfter($from->getTimestamp()); $i < count($boundaries) && $boundaries[$i] <= $until; $i++) {
            foreach ($this->monitorsByBoundary[$boundaries[$i]] as $id) {
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

    /**
     * Built on first use: the engine only needs point lookups. Monitors with identical windows (the targets
     * of one maintenance) share one entry per boundary while the index is built.
     *
     * @return list<int>
     */
    private function boundaries(): array
    {
        if ($this->boundaries !== null) {
            return $this->boundaries;
        }

        /** @var array<string, array{0: list<Window>, 1: list<int>}> $groups */
        $groups = [];
        foreach ($this->byMonitor as $monitorId => $windows) {
            $key = '';
            foreach ($windows as $window) {
                $key .= $window->start->getTimestamp().'-'.($window->end?->getTimestamp() ?? '').',';
            }
            $key = md5($key);
            $groups[$key] ??= [$windows, []];
            $groups[$key][1][] = $monitorId;
        }

        $byBoundary = [];
        foreach ($groups as [$windows, $monitorIds]) {
            foreach ($windows as $window) {
                foreach ([$window->start, $window->end] as $moment) {
                    if ($moment !== null) {
                        $byBoundary[$moment->getTimestamp()] ??= [];
                        array_push($byBoundary[$moment->getTimestamp()], ...$monitorIds);
                    }
                }
            }
        }
        ksort($byBoundary);
        $this->monitorsByBoundary = $byBoundary;

        return $this->boundaries = array_keys($byBoundary);
    }

    private function firstBoundaryAfter(int $timestamp): int
    {
        $boundaries = $this->boundaries();
        $low = 0;
        $high = count($boundaries);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($boundaries[$middle] > $timestamp) {
                $high = $middle;
            } else {
                $low = $middle + 1;
            }
        }

        return $low;
    }
}
