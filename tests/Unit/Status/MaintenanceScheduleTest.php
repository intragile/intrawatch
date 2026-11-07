<?php

namespace Tests\Unit\Status;

use App\Status\Maintenance\MaintenanceSchedule;
use App\Status\Maintenance\Window;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

class MaintenanceScheduleTest extends TestCase
{
    private const BUDAPEST = 'Europe/Budapest';

    private function at(string $utc): CarbonImmutable
    {
        return CarbonImmutable::parse($utc, 'UTC');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function schedule(array $attributes, string $workspaceTimezone = self::BUDAPEST): MaintenanceSchedule
    {
        return MaintenanceSchedule::fromArray($attributes + ['active' => true, 'created_at' => '2027-01-01 00:00:00'], $workspaceTimezone);
    }

    /**
     * @param  list<Window>  $windows
     * @return list<string>
     */
    private function format(array $windows): array
    {
        return array_map(
            fn (Window $w): string => $w->start->format('Y-m-d H:i').'/'.($w->end?->format('Y-m-d H:i') ?? 'open'),
            $windows,
        );
    }

    public function test_once_and_open_ended(): void
    {
        $once = $this->schedule(['strategy' => 'once', 'starts_at' => '2027-02-01 10:00:00', 'ends_at' => '2027-02-01 12:00:00', 'valid_until' => '2020-01-01']);
        $this->assertSame(['2027-02-01 10:00/2027-02-01 12:00'], $this->format($once->windowsBetween($this->at('2027-02-01 00:00'), $this->at('2027-02-02 00:00'))));
        $this->assertSame([], $once->windowsBetween($this->at('2027-02-01 12:00'), $this->at('2027-02-02 00:00')));
        $this->assertSame([], $once->windowsBetween($this->at('2027-01-31 00:00'), $this->at('2027-02-01 10:00')));

        $open = $this->schedule(['strategy' => 'once', 'starts_at' => '2027-02-01 10:00:00', 'ends_at' => null]);
        $windows = $open->windowsBetween($this->at('2030-01-01 00:00'), $this->at('2030-01-02 00:00'));
        $this->assertSame(['2027-02-01 10:00/open'], $this->format($windows));
        $this->assertTrue($windows[0]->contains($this->at('2099-01-01 00:00')));
        $this->assertFalse($windows[0]->contains($this->at('2027-02-01 09:59')));
    }

    public function test_window_contains_and_overlaps_edges(): void
    {
        $w = new Window($this->at('2027-02-01 10:00'), $this->at('2027-02-01 12:00'));
        $this->assertTrue($w->contains($this->at('2027-02-01 10:00')));
        $this->assertFalse($w->contains($this->at('2027-02-01 12:00')));
        $this->assertTrue($w->overlaps($this->at('2027-02-01 11:59'), $this->at('2027-02-01 13:00')));
        $this->assertFalse($w->overlaps($this->at('2027-02-01 12:00'), $this->at('2027-02-01 13:00')));
        $this->assertFalse($w->overlaps($this->at('2027-02-01 09:00'), $this->at('2027-02-01 10:00')));
    }

    public function test_weekly_in_workspace_timezone(): void
    {
        $s = $this->schedule(['strategy' => 'weekly', 'weekdays' => [1, 3], 'start_time' => '22:00', 'duration_minutes' => 120]);
        $this->assertSame(
            ['2027-01-04 21:00/2027-01-04 23:00', '2027-01-06 21:00/2027-01-06 23:00'],
            $this->format($s->windowsBetween($this->at('2027-01-04 00:00'), $this->at('2027-01-11 00:00'))),
        );
    }

    public function test_maintenance_timezone_overrides_workspace_timezone(): void
    {
        $s = $this->schedule(['strategy' => 'weekly', 'weekdays' => [1], 'start_time' => '22:00:00', 'duration_minutes' => 60, 'timezone' => 'UTC']);
        $this->assertSame(
            ['2027-01-04 22:00/2027-01-04 23:00'],
            $this->format($s->windowsBetween($this->at('2027-01-04 00:00'), $this->at('2027-01-05 00:00'))),
        );
    }

    public function test_window_started_before_range_is_included(): void
    {
        $s = $this->schedule(['strategy' => 'weekly', 'weekdays' => [1], 'start_time' => '22:00', 'duration_minutes' => 120]);
        // 22:30 local on Monday, inside the window
        $this->assertSame(
            ['2027-01-04 21:00/2027-01-04 23:00'],
            $this->format($s->windowsBetween($this->at('2027-01-04 21:30'), $this->at('2027-01-04 21:45'))),
        );
        // Window crossing local midnight, range starting after midnight
        $late = $this->schedule(['strategy' => 'weekly', 'weekdays' => [1], 'start_time' => '23:00', 'duration_minutes' => 180]);
        $this->assertSame(
            ['2027-01-04 22:00/2027-01-05 01:00'],
            $this->format($late->windowsBetween($this->at('2027-01-05 00:30'), $this->at('2027-01-05 06:00'))),
        );
    }

    public function test_spring_forward_start_moves_after_the_gap(): void
    {
        $s = $this->schedule(['strategy' => 'weekly', 'weekdays' => [7], 'start_time' => '02:30', 'duration_minutes' => 60]);
        $this->assertSame(
            ['2027-03-28 01:30/2027-03-28 02:30'],
            $this->format($s->windowsBetween($this->at('2027-03-28 00:00'), $this->at('2027-03-29 00:00'))),
        );
    }

    public function test_fall_back_start_uses_first_occurrence(): void
    {
        $s = $this->schedule(['strategy' => 'weekly', 'weekdays' => [7], 'start_time' => '02:30', 'duration_minutes' => 60]);
        $this->assertSame(
            ['2027-10-31 00:30/2027-10-31 01:30'],
            $this->format($s->windowsBetween($this->at('2027-10-31 00:00'), $this->at('2027-11-01 00:00'))),
        );
    }

    public function test_elapsed_duration_across_fall_back(): void
    {
        $s = $this->schedule(['strategy' => 'weekly', 'weekdays' => [7], 'start_time' => '01:30', 'duration_minutes' => 180]);
        $this->assertSame(
            ['2027-10-30 23:30/2027-10-31 02:30'],
            $this->format($s->windowsBetween($this->at('2027-10-31 00:00'), $this->at('2027-11-01 00:00'))),
        );
    }

    public function test_monthly_last_day_and_skipped_days(): void
    {
        $both = $this->schedule(['strategy' => 'monthly', 'month_days' => [31, -1], 'start_time' => '12:00', 'duration_minutes' => 60]);
        $this->assertSame(
            ['2027-02-28 11:00/2027-02-28 12:00'],
            $this->format($both->windowsBetween($this->at('2027-02-01 00:00'), $this->at('2027-03-01 00:00'))),
        );
        $thirtyFirst = $this->schedule(['strategy' => 'monthly', 'month_days' => [31], 'start_time' => '12:00', 'duration_minutes' => 60]);
        $this->assertSame([], $thirtyFirst->windowsBetween($this->at('2027-04-01 00:00'), $this->at('2027-05-01 00:00')));
        $this->assertCount(1, $thirtyFirst->windowsBetween($this->at('2027-03-01 00:00'), $this->at('2027-04-01 00:00')));
    }

    public function test_interval_anchor_and_validity(): void
    {
        $s = $this->schedule([
            'strategy' => 'interval', 'every_days' => 3, 'start_time' => '10:00', 'duration_minutes' => 60,
            'valid_from' => '2027-01-02', 'valid_until' => '2027-01-10', 'timezone' => 'UTC',
        ]);
        $this->assertSame(
            ['2027-01-02 10:00', '2027-01-05 10:00', '2027-01-08 10:00'],
            array_map(fn (Window $w): string => $w->start->format('Y-m-d H:i'), $s->windowsBetween($this->at('2026-12-01 00:00'), $this->at('2027-02-01 00:00'))),
        );
    }

    public function test_interval_anchor_falls_back_to_created_at_local_date(): void
    {
        // 2027-01-01 23:30 UTC is already Jan 2 in Budapest, so the anchor day is Jan 2.
        $s = $this->schedule(['strategy' => 'interval', 'every_days' => 2, 'start_time' => '08:00', 'duration_minutes' => 30, 'created_at' => '2027-01-01 23:30:00']);
        $this->assertSame(
            ['2027-01-02 07:00', '2027-01-04 07:00'],
            array_map(fn (Window $w): string => $w->start->format('Y-m-d H:i'), $s->windowsBetween($this->at('2027-01-01 00:00'), $this->at('2027-01-05 00:00'))),
        );
    }

    public function test_interval_without_any_anchor_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MaintenanceSchedule::fromArray(['strategy' => 'interval', 'every_days' => 2, 'start_time' => '08:00', 'duration_minutes' => 30, 'active' => true], 'UTC');
    }

    public function test_cron_windows(): void
    {
        $s = $this->schedule(['strategy' => 'cron', 'cron' => '0 3 * * 1-5', 'duration_minutes' => 30, 'timezone' => 'UTC']);
        $this->assertSame(
            ['2027-01-04 03:00/2027-01-04 03:30', '2027-01-05 03:00/2027-01-05 03:30'],
            $this->format($s->windowsBetween($this->at('2027-01-04 00:00'), $this->at('2027-01-06 00:00'))),
        );
        // Started before the range
        $this->assertSame(
            ['2027-01-04 03:00/2027-01-04 03:30'],
            $this->format($s->windowsBetween($this->at('2027-01-04 03:10'), $this->at('2027-01-04 04:00'))),
        );
        // Friday to Monday skips the weekend
        $this->assertSame(
            ['2027-01-08 03:00/2027-01-08 03:30', '2027-01-11 03:00/2027-01-11 03:30'],
            $this->format($s->windowsBetween($this->at('2027-01-08 00:00'), $this->at('2027-01-12 00:00'))),
        );
    }

    public function test_cron_validity_limits_local_start_date(): void
    {
        $s = $this->schedule(['strategy' => 'cron', 'cron' => '0 3 * * *', 'duration_minutes' => 30, 'timezone' => 'UTC', 'valid_from' => '2027-01-03', 'valid_until' => '2027-01-04']);
        $this->assertSame(
            ['2027-01-03 03:00', '2027-01-04 03:00'],
            array_map(fn (Window $w): string => $w->start->format('Y-m-d H:i'), $s->windowsBetween($this->at('2027-01-01 00:00'), $this->at('2027-01-10 00:00'))),
        );
    }

    public function test_invalid_cron_has_no_windows(): void
    {
        $s = $this->schedule(['strategy' => 'cron', 'cron' => 'not a cron', 'duration_minutes' => 30]);
        $this->assertSame([], $s->windowsBetween($this->at('2027-01-01 00:00'), $this->at('2027-01-10 00:00')));
        $this->assertNull($s->nextWindow($this->at('2027-01-01 00:00')));
    }

    public function test_overlapping_occurrences_are_returned_as_they_are(): void
    {
        $s = $this->schedule(['strategy' => 'interval', 'every_days' => 1, 'start_time' => '10:00', 'duration_minutes' => 2000, 'timezone' => 'UTC', 'valid_from' => '2027-01-01']);
        $this->assertCount(2, $s->windowsBetween($this->at('2027-01-03 12:00'), $this->at('2027-01-03 13:00')));
    }

    public function test_next_window_returns_current_when_in_progress(): void
    {
        $s = $this->schedule(['strategy' => 'weekly', 'weekdays' => [1], 'start_time' => '22:00', 'duration_minutes' => 120]);
        $current = $s->nextWindow($this->at('2027-01-04 21:30'));
        $this->assertSame('2027-01-04 21:00', $current?->start->format('Y-m-d H:i'));
        $next = $s->nextWindow($this->at('2027-01-04 23:00'));
        $this->assertSame('2027-01-11 21:00', $next?->start->format('Y-m-d H:i'));
        $this->assertNull($this->schedule(['strategy' => 'weekly', 'weekdays' => [1], 'start_time' => '22:00', 'duration_minutes' => 60, 'valid_until' => '2027-01-05'])->nextWindow($this->at('2027-01-05 00:00')));
    }

    public function test_next_window_searches_at_most_400_days(): void
    {
        $s = $this->schedule(['strategy' => 'once', 'starts_at' => '2029-01-01 00:00:00', 'ends_at' => '2029-01-02 00:00:00']);
        $this->assertNotNull($s->nextWindow($this->at('2028-12-31 00:00')));
        $yearly = $this->schedule(['strategy' => 'monthly', 'month_days' => [1], 'start_time' => '00:00', 'duration_minutes' => 60, 'valid_from' => '2028-06-01']);
        $this->assertNull($yearly->nextWindow($this->at('2027-01-01 00:00')));
    }

    public function test_inactive_has_no_windows(): void
    {
        $s = $this->schedule(['strategy' => 'once', 'starts_at' => '2027-02-01 10:00:00', 'ends_at' => null, 'active' => false]);
        $this->assertSame([], $s->windowsBetween($this->at('2027-01-01 00:00'), $this->at('2028-01-01 00:00')));
        $this->assertNull($s->nextWindow($this->at('2027-01-01 00:00')));
    }

    public function test_window_cap(): void
    {
        $s = $this->schedule(['strategy' => 'cron', 'cron' => '* * * * *', 'duration_minutes' => 1, 'timezone' => 'UTC']);
        $windows = $s->windowsBetween($this->at('2027-01-01 00:00'), $this->at('2027-01-11 00:00'));
        $this->assertCount(MaintenanceSchedule::MAX_WINDOWS, $windows);
        $this->assertSame('2027-01-01 00:00', $windows[0]->start->format('Y-m-d H:i'));
    }

    public function test_cron_with_future_valid_from_starts_at_valid_from_quickly(): void
    {
        $s = $this->schedule(['strategy' => 'cron', 'cron' => '* * * * *', 'duration_minutes' => 1, 'timezone' => 'UTC', 'valid_from' => '2027-12-01']);
        $started = microtime(true);
        $next = $s->nextWindow($this->at('2027-01-01 00:00'));
        $this->assertLessThan(0.5, microtime(true) - $started);
        $this->assertSame('2027-12-01 00:00', $next?->start->format('Y-m-d H:i'));

        $budapest = $this->schedule(['strategy' => 'cron', 'cron' => '0 0 * * *', 'duration_minutes' => 10, 'valid_from' => '2027-12-01']);
        $this->assertSame('2027-11-30 23:00', $budapest->nextWindow($this->at('2027-01-01 00:00'))?->start->format('Y-m-d H:i'));
    }

    public function test_cron_across_dst_in_budapest(): void
    {
        $s = $this->schedule(['strategy' => 'cron', 'cron' => '30 2 * * *', 'duration_minutes' => 60]);
        // Spring forward: the skipped 02:30 runs at the jump, 03:00 CEST.
        $this->assertSame(
            ['2027-03-28 01:00/2027-03-28 02:00'],
            $this->format($s->windowsBetween($this->at('2027-03-28 00:00'), $this->at('2027-03-28 12:00'))),
        );
        // Fall back: the repeated 02:30 runs once, at its first occurrence (CEST).
        $this->assertSame(
            ['2027-10-31 00:30/2027-10-31 01:30'],
            $this->format($s->windowsBetween($this->at('2027-10-31 00:00'), $this->at('2027-10-31 12:00'))),
        );
    }

    public function test_invalid_definitions_throw_invalid_argument(): void
    {
        $bad = [
            ['strategy' => 'bogus'],
            ['strategy' => null],
            ['strategy' => 'weekly', 'timezone' => 'Mars/Base'],
            ['strategy' => 'weekly', 'start_time' => '25:00'],
            ['strategy' => 'weekly', 'start_time' => 'noon'],
        ];
        foreach ($bad as $attributes) {
            try {
                MaintenanceSchedule::fromArray($attributes, 'UTC');
                $this->fail('Expected InvalidArgumentException for '.json_encode($attributes));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
