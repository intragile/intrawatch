<?php

namespace App\Status\Maintenance;

use App\Enums\MaintenanceStrategy;
use App\Models\Maintenance;
use App\Monitoring\Engine\CronSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use InvalidArgumentException;

/**
 * Turns a maintenance definition into concrete UTC windows.
 *
 * Pure: it holds no clock and does no I/O. Day-based strategies (interval, weekly, monthly) pick occurrence
 * days in the effective timezone and start at a local wall time; cron strategies use the cron runs.
 * A wall time inside a spring-forward gap moves forward by the gap, a repeated wall time uses its first
 * instant, and a window lasts its duration in elapsed minutes.
 */
final class MaintenanceSchedule
{
    public const MAX_WINDOWS = 5000;

    private const NEXT_WINDOW_DAYS = 400;

    private const SECONDS_PER_DAY = 86400;

    private readonly DateTimeZone $zone;

    /**
     * @param  list<int>  $weekdays
     * @param  list<int>  $monthDays
     */
    private function __construct(
        private readonly bool $active,
        private readonly MaintenanceStrategy $strategy,
        string $timezone,
        private readonly ?CarbonImmutable $startsAt,
        private readonly ?CarbonImmutable $endsAt,
        private readonly ?string $startTime,
        private readonly ?int $durationMinutes,
        private readonly ?int $everyDays,
        private readonly array $weekdays,
        private readonly array $monthDays,
        private readonly ?string $cron,
        private readonly ?string $validFrom,
        private readonly ?string $validUntil,
        private readonly ?string $anchorDate,
    ) {
        $this->zone = new DateTimeZone($timezone);
    }

    public static function fromModel(Maintenance $maintenance, string $workspaceTimezone): self
    {
        return self::fromArray([
            'active' => $maintenance->active,
            'strategy' => $maintenance->strategy,
            'timezone' => $maintenance->timezone,
            'starts_at' => $maintenance->starts_at,
            'ends_at' => $maintenance->ends_at,
            'start_time' => $maintenance->start_time,
            'duration_minutes' => $maintenance->duration_minutes,
            'every_days' => $maintenance->every_days,
            'weekdays' => $maintenance->weekdays,
            'month_days' => $maintenance->month_days,
            'cron' => $maintenance->cron,
            'valid_from' => $maintenance->valid_from,
            'valid_until' => $maintenance->valid_until,
            'created_at' => $maintenance->created_at,
        ], $workspaceTimezone);
    }

    /**
     * @param  array<string, mixed>  $attributes  The maintenance columns; `created_at` anchors an interval without `valid_from`.
     *
     * @throws InvalidArgumentException when an interval schedule has neither `valid_from` nor `created_at`
     */
    public static function fromArray(array $attributes, string $workspaceTimezone): self
    {
        $strategy = $attributes['strategy'] ?? null;
        $strategy = $strategy instanceof MaintenanceStrategy ? $strategy : MaintenanceStrategy::from(self::string($strategy) ?? '');

        $timezone = self::string($attributes['timezone'] ?? null) ?? $workspaceTimezone;
        $validFrom = self::date($attributes['valid_from'] ?? null);

        $anchor = $validFrom;
        if ($strategy === MaintenanceStrategy::Interval && $anchor === null) {
            $createdAt = self::instant($attributes['created_at'] ?? null);
            if ($createdAt === null) {
                throw new InvalidArgumentException('An interval schedule needs valid_from or created_at as its anchor.');
            }
            $anchor = $createdAt->setTimezone($timezone)->format('Y-m-d');
        }

        return new self(
            active: (bool) ($attributes['active'] ?? true),
            strategy: $strategy,
            timezone: $timezone,
            startsAt: self::instant($attributes['starts_at'] ?? null),
            endsAt: self::instant($attributes['ends_at'] ?? null),
            startTime: self::string($attributes['start_time'] ?? null),
            durationMinutes: self::int($attributes['duration_minutes'] ?? null),
            everyDays: self::int($attributes['every_days'] ?? null),
            weekdays: self::ints($attributes['weekdays'] ?? null),
            monthDays: self::ints($attributes['month_days'] ?? null),
            cron: self::string($attributes['cron'] ?? null),
            validFrom: $validFrom,
            validUntil: self::date($attributes['valid_until'] ?? null),
            anchorDate: $anchor,
        );
    }

    /**
     * Every window that overlaps [$from, $to), including one that started before $from, ordered by start.
     * At most MAX_WINDOWS are returned.
     *
     * @return list<Window>
     */
    public function windowsBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $windows = [];
        foreach ($this->occurrences($from, $to) as $window) {
            $windows[] = $window;
            if (count($windows) >= self::MAX_WINDOWS) {
                break;
            }
        }

        return $windows;
    }

    /**
     * The first window that ends after $after (the current one when in progress), looking 400 days ahead.
     */
    public function nextWindow(CarbonImmutable $after): ?Window
    {
        foreach ($this->occurrences($after, $after->addDays(self::NEXT_WINDOW_DAYS)) as $window) {
            return $window;
        }

        return null;
    }

    /**
     * @return Generator<int, Window>
     */
    private function occurrences(CarbonImmutable $from, CarbonImmutable $to): Generator
    {
        if (! $this->active) {
            return;
        }

        if ($this->strategy === MaintenanceStrategy::Once) {
            if ($this->startsAt !== null) {
                $window = new Window($this->startsAt, $this->endsAt);
                if ($window->overlaps($from, $to)) {
                    yield $window;
                }
            }

            return;
        }

        if ($this->durationMinutes === null || $this->durationMinutes < 1) {
            return;
        }

        yield from $this->strategy === MaintenanceStrategy::Cron
            ? $this->cronOccurrences($from, $to)
            : $this->dayOccurrences($from, $to);
    }

    /**
     * @return Generator<int, Window>
     */
    private function cronOccurrences(CarbonImmutable $from, CarbonImmutable $to): Generator
    {
        $schedule = $this->cron === null ? null : CronSchedule::parse($this->cron, $this->zone->getName());
        if ($schedule === null) {
            return;
        }

        $cursor = $from->subMinutes((int) $this->durationMinutes);
        while (($start = $schedule->nextAfter($cursor)) !== null && $start->lt($to)) {
            $cursor = $start;
            $date = $start->setTimezone($this->zone)->format('Y-m-d');
            if ($this->validFrom !== null && $date < $this->validFrom) {
                continue;
            }
            if ($this->validUntil !== null && $date > $this->validUntil) {
                return;
            }
            yield $this->window($start);
        }
    }

    /**
     * @return Generator<int, Window>
     */
    private function dayOccurrences(CarbonImmutable $from, CarbonImmutable $to): Generator
    {
        $time = $this->startTime;
        if ($time === null) {
            return;
        }

        $first = $this->localDate($from->subMinutes((int) $this->durationMinutes))->subDay();
        $last = $this->localDate($to)->addDay();
        if ($this->validFrom !== null && $first->format('Y-m-d') < $this->validFrom) {
            $first = CarbonImmutable::parse($this->validFrom, 'UTC');
        }
        if ($this->validUntil !== null && $last->format('Y-m-d') > $this->validUntil) {
            $last = CarbonImmutable::parse($this->validUntil, 'UTC');
        }

        for ($day = $first; $day->lte($last); $day = $day->addDay()) {
            if (! $this->occursOn($day)) {
                continue;
            }
            $window = $this->window($this->localInstant($day->format('Y-m-d'), $time));
            if ($window->overlaps($from, $to)) {
                yield $window;
            }
        }
    }

    private function occursOn(CarbonImmutable $day): bool
    {
        switch ($this->strategy) {
            case MaintenanceStrategy::Weekly:
                return in_array((int) $day->format('N'), $this->weekdays, true);
            case MaintenanceStrategy::Monthly:
                $dayOfMonth = (int) $day->format('j');

                return in_array($dayOfMonth, $this->monthDays, true)
                    || ($dayOfMonth === (int) $day->format('t') && in_array(-1, $this->monthDays, true));
            case MaintenanceStrategy::Interval:
                if ($this->everyDays === null || $this->everyDays < 1 || $this->anchorDate === null) {
                    return false;
                }
                $offset = intdiv($day->getTimestamp() - CarbonImmutable::parse($this->anchorDate, 'UTC')->getTimestamp(), self::SECONDS_PER_DAY);

                return (($offset % $this->everyDays) + $this->everyDays) % $this->everyDays === 0;
            default:
                return false;
        }
    }

    private function window(CarbonImmutable $start): Window
    {
        return new Window($start, $start->addMinutes((int) $this->durationMinutes));
    }

    /**
     * The local calendar date of an instant, as midnight UTC so days can be stepped without DST effects.
     */
    private function localDate(CarbonImmutable $instant): CarbonImmutable
    {
        return CarbonImmutable::parse($instant->setTimezone($this->zone)->format('Y-m-d'), 'UTC');
    }

    /**
     * The instant of a local wall time: a time in a spring-forward gap moves forward by the gap and a
     * repeated time resolves to its first occurrence.
     */
    private function localInstant(string $date, string $time): CarbonImmutable
    {
        $wall = new DateTimeImmutable($date.' '.$time, new DateTimeZone('UTC'));
        $text = $wall->format('Y-m-d H:i:s');
        $timestamp = (new DateTimeImmutable($text, $this->zone))->getTimestamp();

        foreach ([3600, 1800] as $shift) {
            $earlier = (new DateTimeImmutable('@'.($timestamp - $shift)))->setTimezone($this->zone);
            if ($earlier->format('Y-m-d H:i:s') === $text) {
                $timestamp -= $shift;
                break;
            }
        }

        return CarbonImmutable::createFromTimestampUTC($timestamp);
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    private static function instant(mixed $value): ?CarbonImmutable
    {
        return match (true) {
            $value instanceof CarbonInterface => CarbonImmutable::instance($value)->setTimezone('UTC'),
            $value instanceof \DateTimeInterface => CarbonImmutable::instance($value)->setTimezone('UTC'),
            is_string($value) && $value !== '' => CarbonImmutable::parse($value, 'UTC')->setTimezone('UTC'),
            default => null,
        };
    }

    /**
     * @return list<int>
     */
    private static function ints(mixed $value): array
    {
        $ints = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (($int = self::int($item)) !== null) {
                $ints[] = $int;
            }
        }

        return $ints;
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : null;
    }
}
