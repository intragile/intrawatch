<?php

namespace Tests\Unit\Status;

use App\Status\Maintenance\MaintenanceCalendar;
use App\Status\Maintenance\Window;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class MaintenanceCalendarTest extends TestCase
{
    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse("2027-03-01 {$time}", 'UTC');
    }

    private function window(string $start, ?string $end): Window
    {
        return new Window($this->at($start), $end === null ? null : $this->at($end));
    }

    /**
     * @param  array<int, list<Window>>  $byMonitor
     */
    private function calendar(array $byMonitor): MaintenanceCalendar
    {
        return new MaintenanceCalendar($this->at('00:00')->subDay(), $this->at('00:00')->addDays(2), $byMonitor);
    }

    public function test_point_lookup_uses_half_open_windows(): void
    {
        $calendar = $this->calendar([7 => [$this->window('10:00', '11:00'), $this->window('13:00', '14:00')]]);

        $this->assertSame([7], $calendar->underMaintenance([7], $this->at('10:00')));
        $this->assertSame([], $calendar->underMaintenance([7], $this->at('11:00')));
        $this->assertSame([], $calendar->underMaintenance([7], $this->at('12:00')));
        $this->assertSame([7], $calendar->underMaintenance([7, 8], $this->at('13:30')));
        $this->assertSame([], $calendar->underMaintenance([8], $this->at('13:30')));
    }

    public function test_open_ended_window_never_ends(): void
    {
        $calendar = $this->calendar([3 => [$this->window('10:00', null)]]);

        $this->assertSame([], $calendar->underMaintenance([3], $this->at('09:59')));
        $this->assertSame([3], $calendar->underMaintenance([3], $this->at('23:00')));
        $this->assertNull($calendar->nextBoundaryAfter($this->at('10:00')));
    }

    public function test_boundaries(): void
    {
        $calendar = $this->calendar([
            1 => [$this->window('10:00', '11:00')],
            2 => [$this->window('10:30', '12:00')],
        ]);

        $this->assertSame($this->at('10:00')->getTimestamp(), $calendar->nextBoundaryAfter($this->at('09:00'))?->getTimestamp());
        $this->assertSame($this->at('10:30')->getTimestamp(), $calendar->nextBoundaryAfter($this->at('10:00'))?->getTimestamp());
        $this->assertNull($calendar->nextBoundaryAfter($this->at('12:00')));

        $this->assertSame([1], $calendar->monitorsWithBoundaryIn($this->at('09:00'), $this->at('10:00')));
        $this->assertSame([], $calendar->monitorsWithBoundaryIn($this->at('10:00')->subSecond(), $this->at('10:00')->subSecond()));
        $this->assertEqualsCanonicalizing([1, 2], $calendar->monitorsWithBoundaryIn($this->at('10:00'), $this->at('11:00')));
    }

    public function test_covers_is_the_horizon(): void
    {
        $calendar = $this->calendar([]);

        $this->assertTrue($calendar->covers($this->at('00:00')));
        $this->assertFalse($calendar->covers($this->at('00:00')->subDays(2)));
        $this->assertFalse($calendar->covers($this->at('00:00')->addDays(2)));
    }
}
