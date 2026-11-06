<?php

namespace App\Monitoring\Engine;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * The runs of a cron expression in a timezone, the way cron daemons such as cronie run them.
 *
 * The expression is matched against local wall-clock times, which are then mapped to instants:
 * - A wall-clock time that does not exist (the hour skipped when clocks spring forward) runs at the jump.
 *   All such runs of one jump are the same instant, so one report at or after the jump satisfies them.
 * - A wall-clock time that happens twice (the hour repeated when clocks fall back) runs once, at its first
 *   occurrence. Runs are thereby de-duplicated by local wall-clock time per day.
 */
final class CronSchedule
{
    /**
     * Upper bound of candidates looked at per call; only the repeated hour makes the search skip any.
     */
    private const MAX_STEPS = 1500;

    /**
     * Most a report may precede its run and still count for it, absorbing clock skew between hosts.
     */
    private const MAX_EARLY_SECONDS = 60;

    private function __construct(
        private readonly CronExpression $cron,
        private readonly DateTimeZone $zone,
    ) {}

    public static function parse(string $expression, string $timezone): ?self
    {
        try {
            return new self(new CronExpression($expression), new DateTimeZone($timezone));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The first run strictly after $after, or null when there is none.
     */
    public function nextAfter(CarbonImmutable $after): ?CarbonImmutable
    {
        $utc = new DateTimeZone('UTC');
        $wall = new DateTimeImmutable($after->setTimezone($this->zone)->format('Y-m-d H:i:s'), $utc);

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            try {
                $wall = DateTimeImmutable::createFromMutable($this->cron->getNextRunDate($wall, 0, false, 'UTC'));
            } catch (Throwable) {
                return null;
            }
            $instant = $this->instant($wall);
            if ($instant->gt($after)) {
                return $instant;
            }
        }

        return null;
    }

    /**
     * The run expected next after a report at $reportedAt.
     *
     * A report satisfies every run up to a small tolerance after it: min(60 s, half the gap of the interval
     * the report falls into, from the last run at or before it to the next one). A job whose clock runs a
     * few seconds ahead therefore reports the upcoming run, not the previous one, and in closely spaced
     * slots a run's own finish never counts for the next slot.
     */
    public function expectedAfter(CarbonImmutable $reportedAt): ?CarbonImmutable
    {
        $next = $this->nextAfter($reportedAt);
        if ($next === null) {
            return null;
        }

        $tolerance = self::MAX_EARLY_SECONDS;
        $previous = $this->previousAtOrBefore($reportedAt);
        if ($previous !== null) {
            $tolerance = min($tolerance, intdiv($next->getTimestamp() - $previous->getTimestamp(), 2));
        }

        return $next->getTimestamp() - $reportedAt->getTimestamp() <= $tolerance ? $this->nextAfter($next) : $next;
    }

    /**
     * The last run at or before $at, or null when there is none.
     */
    public function previousAtOrBefore(CarbonImmutable $at): ?CarbonImmutable
    {
        $utc = new DateTimeZone('UTC');
        $wall = new DateTimeImmutable($at->setTimezone($this->zone)->format('Y-m-d H:i:s'), $utc);
        $allowCurrent = true;

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            try {
                $wall = DateTimeImmutable::createFromMutable($this->cron->getPreviousRunDate($wall, 0, $allowCurrent, 'UTC'));
            } catch (Throwable) {
                return null;
            }
            $allowCurrent = false;
            $instant = $this->instant($wall);
            if ($instant->lte($at)) {
                return $instant;
            }
        }

        return null;
    }

    /**
     * The instant a local wall-clock time runs at.
     */
    private function instant(DateTimeImmutable $wall): CarbonImmutable
    {
        $text = $wall->format('Y-m-d H:i:s');
        $local = new DateTimeImmutable($text, $this->zone);
        $timestamp = $local->getTimestamp();

        if ($local->format('Y-m-d H:i:s') !== $text) {
            // Skipped by a forward jump; PHP moved it past the jump, which is the last transition before it.
            $jump = $timestamp;
            foreach ($this->zone->getTransitions($timestamp - 3 * 3600, $timestamp) as $index => $transition) {
                if ($index > 0) {
                    $jump = $transition['ts'];
                }
            }

            return CarbonImmutable::createFromTimestamp($jump, $this->zone);
        }

        // PHP resolves a repeated wall-clock time to its second occurrence; the first one is when it runs.
        foreach ([3600, 1800, 7200] as $shift) {
            $earlier = (new DateTimeImmutable('@'.($timestamp - $shift)))->setTimezone($this->zone);
            if ($earlier->format('Y-m-d H:i:s') === $text) {
                return CarbonImmutable::createFromTimestamp($timestamp - $shift, $this->zone);
            }
        }

        return CarbonImmutable::createFromTimestamp($timestamp, $this->zone);
    }
}
