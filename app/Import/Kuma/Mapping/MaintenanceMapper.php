<?php

declare(strict_types=1);

namespace App\Import\Kuma\Mapping;

use App\Import\Kuma\Source\Cast;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Translates a Kuma `maintenance` row. Kuma stores `start_date` and `end_date` as wall times in the
 * maintenance's timezone, `duration` in seconds, weekdays as ISO numbers (1 = Monday, 7 = Sunday, the
 * convention of IntraWatch's schedule too) and `lastDay1` in the days of the month for the last day.
 */
final class MaintenanceMapper
{
    private const STAMP = 'Y-m-d\TH:i:s\Z';

    private const MAX_MINUTES = 10080;

    /**
     * @param  array<string, mixed>  $row  A Kuma `maintenance` row.
     * @param  string  $workspaceTimezone  Stands in for Kuma's `SAME_AS_SERVER`.
     */
    public function map(array $row, string $workspaceTimezone): Mapped|Unmappable
    {
        $notes = [];
        $strategy = Cast::string($row['strategy'] ?? null);
        $title = trim(Cast::string($row['title'] ?? null));

        $timezone = trim(Cast::string($row['timezone'] ?? null));
        if ($timezone === '' || $timezone === 'SAME_AS_SERVER') {
            $timezone = $workspaceTimezone;
            $notes[] = Note::of('timezone_same_as_server', "The maintenance used the Kuma server's timezone; it was set to the workspace timezone ({$workspaceTimezone}).");
        }

        $attributes = [
            'title' => $title,
            'description' => $this->text($row['description'] ?? null),
            'timezone' => $timezone,
            'active' => Cast::int($row['active'] ?? 0) === 1,
        ];

        switch ($strategy) {
            case 'manual':
                return new Unmappable(Note::of('manual_maintenance', 'A manual maintenance has no schedule; IntraWatch maintenances run on a schedule, so it was skipped.'));

            case 'single':
                return new Mapped($attributes + [
                    'strategy' => 'once',
                    'starts_at' => $this->utc($row['start_date'] ?? null, $timezone),
                    'ends_at' => $this->utc($row['end_date'] ?? null, $timezone),
                ], $notes);

            case 'recurring-interval':
                $attributes += ['strategy' => 'interval', 'every_days' => Cast::int($row['interval_day'] ?? null)] + $this->window($row);

                return new Mapped($attributes, $notes);

            case 'recurring-weekday':
                $weekdays = $this->weekdays($row['weekdays'] ?? null);
                if ($weekdays === []) {
                    return new Unmappable(Note::of('maintenance_without_days', 'The recurring maintenance has no weekdays, so it never runs and was skipped.'));
                }

                return new Mapped($attributes + ['strategy' => 'weekly', 'weekdays' => $weekdays] + $this->window($row), $notes);

            case 'recurring-day-of-month':
                [$days, $dropped] = $this->monthDays($row['days_of_month'] ?? null);
                if ($dropped) {
                    $notes[] = Note::of('month_days_dropped', 'Kuma days relative to the end of the month other than the last day (lastDay2 to lastDay4) have no equivalent and were dropped.');
                }
                if ($days === []) {
                    return new Unmappable(Note::of('maintenance_without_days', 'The recurring maintenance has no days of the month, so it never runs and was skipped.'));
                }

                return new Mapped($attributes + ['strategy' => 'monthly', 'month_days' => $days] + $this->window($row), $notes);

            case 'cron':
                $window = $this->window($row);
                unset($window['start_time']);

                return new Mapped($attributes + ['strategy' => 'cron', 'cron' => trim(Cast::string($row['cron'] ?? null))] + $window, $notes);

            default:
                return new Unmappable(Note::of('unsupported_strategy', "The maintenance strategy '{$strategy}' is not supported; it was skipped."));
        }
    }

    /**
     * The time of day, duration and validity dates shared by the recurring strategies.
     *
     * @param  array<string, mixed>  $row
     * @return array{start_time: ?string, duration_minutes: int, valid_from: ?string, valid_until: ?string}
     */
    private function window(array $row): array
    {
        $start = $this->clock($row['start_time'] ?? null);
        $seconds = Cast::int($row['duration'] ?? null);
        if ($seconds <= 0) {
            $end = $this->clock($row['end_time'] ?? null);
            $seconds = 0;
            if ($start !== null && $end !== null) {
                $seconds = (((int) substr($end, 0, 2) * 60 + (int) substr($end, 3, 2)) - ((int) substr($start, 0, 2) * 60 + (int) substr($start, 3, 2)) + 1440) % 1440 * 60;
            }
        }

        return [
            'start_time' => $start,
            'duration_minutes' => max(1, min(self::MAX_MINUTES, (int) ceil($seconds / 60))),
            'valid_from' => $this->day($row['start_date'] ?? null),
            'valid_until' => $this->day($row['end_date'] ?? null),
        ];
    }

    /**
     * @return list<int>
     */
    private function weekdays(mixed $value): array
    {
        $days = [];
        foreach ($this->list($value) as $day) {
            if (is_numeric($day) && (int) $day >= 1 && (int) $day <= 7) {
                $days[(int) $day] = (int) $day;
            }
        }
        ksort($days);

        return array_values($days);
    }

    /**
     * @return array{0: list<int>, 1: bool} The days, and whether some entry had to be dropped.
     */
    private function monthDays(mixed $value): array
    {
        $days = [];
        $dropped = false;
        foreach ($this->list($value) as $day) {
            if ($day === 'lastDay1') {
                $days[-1] = -1;
            } elseif (is_numeric($day) && (int) $day >= 1 && (int) $day <= 31) {
                $days[(int) $day] = (int) $day;
            } else {
                $dropped = true;
            }
        }
        ksort($days);

        return [array_values($days), $dropped];
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * `HH:MM` from `HH:MM` or `HH:MM:SS`, null when absent or malformed.
     */
    private function clock(mixed $value): ?string
    {
        $text = trim(Cast::string($value));

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $text) === 1 ? substr($text, 0, 5) : null;
    }

    /**
     * The date part of a wall time, which needs no timezone conversion.
     */
    private function day(mixed $value): ?string
    {
        $text = trim(Cast::string($value));

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $text) === 1 ? substr($text, 0, 10) : null;
    }

    /**
     * A wall time in the given timezone as a UTC instant; null when absent or unreadable.
     */
    private function utc(mixed $value, string $timezone): ?string
    {
        $text = trim(Cast::string($value));
        if ($text === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($text, $timezone)->utc()->format(self::STAMP);
        } catch (Throwable) {
            return null;
        }
    }

    private function text(mixed $value): ?string
    {
        $text = Cast::string($value);

        return trim($text) === '' ? null : $text;
    }
}
