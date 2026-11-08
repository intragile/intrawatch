<?php

declare(strict_types=1);

namespace Tests\Unit\Import\Kuma;

use App\Import\Kuma\Mapping\MaintenanceMapper;
use App\Import\Kuma\Mapping\Mapped;
use App\Import\Kuma\Mapping\Unmappable;
use Tests\TestCase;

final class MaintenanceMapperTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function map(array $overrides, string $workspaceTimezone = 'Europe/Berlin'): Mapped|Unmappable
    {
        return (new MaintenanceMapper)->map(array_replace([
            'id' => 1, 'title' => 'M', 'description' => null, 'active' => 1, 'strategy' => 'single', 'start_date' => null,
            'end_date' => null, 'start_time' => null, 'end_time' => null, 'weekdays' => '[]', 'days_of_month' => '[]',
            'interval_day' => 1, 'cron' => null, 'timezone' => 'UTC', 'duration' => null,
        ], $overrides), $workspaceTimezone);
    }

    private function mapped(Mapped|Unmappable $result): Mapped
    {
        $this->assertInstanceOf(Mapped::class, $result);

        return $result;
    }

    /**
     * @return list<string>
     */
    private function codes(Mapped $mapped): array
    {
        return array_map(fn ($note): string => $note->code, $mapped->notes);
    }

    public function test_single_converts_local_times_to_utc(): void
    {
        $mapped = $this->mapped($this->map([
            'strategy' => 'single', 'start_date' => '2026-12-01 01:00', 'end_date' => '2026-12-01 02:00', 'timezone' => 'Europe/Budapest',
        ]));

        $this->assertSame('once', $mapped->attributes['strategy']);
        $this->assertSame('2026-12-01T00:00:00Z', $mapped->attributes['starts_at']);
        $this->assertSame('2026-12-01T01:00:00Z', $mapped->attributes['ends_at']);
        $this->assertSame('Europe/Budapest', $mapped->attributes['timezone']);
        $this->assertSame([], $this->codes($mapped));
    }

    public function test_single_in_summer_time_uses_the_summer_offset(): void
    {
        $mapped = $this->mapped($this->map(['start_date' => '2026-07-01 02:00', 'end_date' => '2026-07-01 03:00', 'timezone' => 'Europe/Budapest']));

        $this->assertSame('2026-07-01T00:00:00Z', $mapped->attributes['starts_at']);
    }

    public function test_utc_single_keeps_its_times(): void
    {
        $mapped = $this->mapped($this->map(['start_date' => '2026-12-01 01:00', 'end_date' => '2026-12-01 02:00']));

        $this->assertSame('2026-12-01T01:00:00Z', $mapped->attributes['starts_at']);
    }

    public function test_interval_maps_every_days_and_window(): void
    {
        $mapped = $this->mapped($this->map([
            'strategy' => 'recurring-interval', 'interval_day' => 3, 'start_date' => '2026-11-01 00:00', 'start_time' => '02:00', 'end_time' => '03:00', 'duration' => 3600,
        ]));

        $this->assertSame('interval', $mapped->attributes['strategy']);
        $this->assertSame(3, $mapped->attributes['every_days']);
        $this->assertSame('02:00', $mapped->attributes['start_time']);
        $this->assertSame(60, $mapped->attributes['duration_minutes']);
        $this->assertSame('2026-11-01', $mapped->attributes['valid_from']);
        $this->assertNull($mapped->attributes['valid_until']);
    }

    public function test_weekdays_keep_the_iso_numbers_with_sunday_as_seven(): void
    {
        $mapped = $this->mapped($this->map(['strategy' => 'recurring-weekday', 'weekdays' => '[7,1,1,9]', 'start_time' => '02:00', 'duration' => 60]));

        $this->assertSame('weekly', $mapped->attributes['strategy']);
        $this->assertSame([1, 7], $mapped->attributes['weekdays']);
        $this->assertSame(1, $mapped->attributes['duration_minutes']);
    }

    public function test_weekday_without_days_is_skipped(): void
    {
        $result = $this->map(['strategy' => 'recurring-weekday', 'weekdays' => '[]']);

        $this->assertInstanceOf(Unmappable::class, $result);
        $this->assertSame('maintenance_without_days', $result->reason->code);
    }

    public function test_month_days_translate_last_day_and_drop_the_others(): void
    {
        $mapped = $this->mapped($this->map(['strategy' => 'recurring-day-of-month', 'days_of_month' => '[1,"lastDay1","lastDay2",15]', 'start_time' => '02:00', 'duration' => 7200]));

        $this->assertSame('monthly', $mapped->attributes['strategy']);
        $this->assertSame([-1, 1, 15], $mapped->attributes['month_days']);
        $this->assertSame(['month_days_dropped'], $this->codes($mapped));
    }

    public function test_month_days_that_all_vanish_are_skipped(): void
    {
        $this->assertInstanceOf(Unmappable::class, $this->map(['strategy' => 'recurring-day-of-month', 'days_of_month' => '["lastDay3"]']));
        $this->assertInstanceOf(Unmappable::class, $this->map(['strategy' => 'recurring-day-of-month', 'days_of_month' => '[]']));
    }

    public function test_cron_maps_expression_and_duration(): void
    {
        $mapped = $this->mapped($this->map(['strategy' => 'cron', 'cron' => '30 3 * * *', 'duration' => 2700]));

        $this->assertSame('cron', $mapped->attributes['strategy']);
        $this->assertSame('30 3 * * *', $mapped->attributes['cron']);
        $this->assertSame(45, $mapped->attributes['duration_minutes']);
        $this->assertArrayNotHasKey('start_time', $mapped->attributes);
    }

    public function test_manual_is_skipped_with_its_note(): void
    {
        $result = $this->map(['strategy' => 'manual']);

        $this->assertInstanceOf(Unmappable::class, $result);
        $this->assertSame('manual_maintenance', $result->reason->code);
    }

    public function test_same_as_server_uses_the_workspace_timezone_with_a_note(): void
    {
        $mapped = $this->mapped($this->map(['strategy' => 'single', 'timezone' => 'SAME_AS_SERVER', 'start_date' => '2026-12-01 01:00', 'end_date' => '2026-12-01 02:00']));

        $this->assertSame('Europe/Berlin', $mapped->attributes['timezone']);
        $this->assertSame('2026-12-01T00:00:00Z', $mapped->attributes['starts_at']);
        $this->assertSame(['timezone_same_as_server'], $this->codes($mapped));
    }

    public function test_duration_falls_back_to_the_clock_span_and_is_clamped(): void
    {
        $span = $this->mapped($this->map(['strategy' => 'recurring-interval', 'start_time' => '22:00', 'end_time' => '02:30', 'duration' => 0]));
        $this->assertSame(270, $span->attributes['duration_minutes']);

        $long = $this->mapped($this->map(['strategy' => 'recurring-interval', 'start_time' => '02:00', 'duration' => 99999999]));
        $this->assertSame(10080, $long->attributes['duration_minutes']);

        $none = $this->mapped($this->map(['strategy' => 'recurring-interval', 'start_time' => '02:00', 'duration' => null, 'end_time' => null]));
        $this->assertSame(1, $none->attributes['duration_minutes']);
    }

    public function test_seconds_round_up_to_whole_minutes(): void
    {
        $mapped = $this->mapped($this->map(['strategy' => 'recurring-interval', 'start_time' => '02:00', 'duration' => 61]));

        $this->assertSame(2, $mapped->attributes['duration_minutes']);
    }

    public function test_active_flag_is_kept(): void
    {
        $this->assertFalse($this->mapped($this->map(['active' => 0]))->attributes['active']);
    }

    public function test_unknown_strategy_is_skipped(): void
    {
        $result = $this->map(['strategy' => 'lunar']);

        $this->assertInstanceOf(Unmappable::class, $result);
        $this->assertSame('unsupported_strategy', $result->reason->code);
    }
}
