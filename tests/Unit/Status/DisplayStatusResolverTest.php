<?php

namespace Tests\Unit\Status;

use App\Enums\DisplayStatus;
use App\Enums\IncidentImpact;
use App\Enums\MonitorStatus;
use App\Status\Pages\DayBar;
use App\Status\Pages\DisplayStatusResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DisplayStatusResolverTest extends TestCase
{
    /**
     * @return array<string, array{MonitorStatus, DisplayStatus}>
     */
    public static function plainStatuses(): array
    {
        return [
            'up' => [MonitorStatus::Up, DisplayStatus::Operational],
            'degraded' => [MonitorStatus::Degraded, DisplayStatus::Degraded],
            'down' => [MonitorStatus::Down, DisplayStatus::MajorOutage],
            'maintenance' => [MonitorStatus::Maintenance, DisplayStatus::Maintenance],
            'pending' => [MonitorStatus::Pending, DisplayStatus::Unknown],
            'paused' => [MonitorStatus::Paused, DisplayStatus::Unknown],
        ];
    }

    #[DataProvider('plainStatuses')]
    public function test_monitor_status_maps_to_display_status(MonitorStatus $status, DisplayStatus $expected): void
    {
        $this->assertSame($expected, DisplayStatusResolver::item($status, null, false, []));
    }

    public function test_down_group_with_an_available_child_is_a_partial_outage(): void
    {
        $this->assertSame(DisplayStatus::PartialOutage, DisplayStatusResolver::item(MonitorStatus::Down, ['up' => 1, 'down' => 1], false, []));
        $this->assertSame(DisplayStatus::PartialOutage, DisplayStatusResolver::item(MonitorStatus::Down, ['degraded' => 1, 'down' => 2], false, []));
        $this->assertSame(DisplayStatus::MajorOutage, DisplayStatusResolver::item(MonitorStatus::Down, ['down' => 2, 'pending' => 1], false, []));
        $this->assertSame(DisplayStatus::MajorOutage, DisplayStatusResolver::item(MonitorStatus::Down, [], false, []));
    }

    public function test_active_window_means_maintenance(): void
    {
        $this->assertSame(DisplayStatus::Maintenance, DisplayStatusResolver::item(MonitorStatus::Down, null, true, []));
        $this->assertSame(DisplayStatus::Maintenance, DisplayStatusResolver::item(MonitorStatus::Up, ['up' => 1], true, []));
    }

    public function test_open_incident_impacts_raise_the_status(): void
    {
        $this->assertSame(DisplayStatus::PartialOutage, DisplayStatusResolver::item(MonitorStatus::Up, null, false, [IncidentImpact::Major]));
        $this->assertSame(DisplayStatus::MajorOutage, DisplayStatusResolver::item(MonitorStatus::Degraded, null, false, [IncidentImpact::Minor, IncidentImpact::Critical]));
        $this->assertSame(DisplayStatus::Operational, DisplayStatusResolver::item(MonitorStatus::Up, null, false, [IncidentImpact::None]));
        $this->assertSame(DisplayStatus::MajorOutage, DisplayStatusResolver::item(MonitorStatus::Down, null, false, [IncidentImpact::Minor]));
        $this->assertSame(DisplayStatus::PartialOutage, DisplayStatusResolver::item(MonitorStatus::Maintenance, null, false, [IncidentImpact::Major]));
    }

    public function test_overall_ignores_unknown_unless_everything_is_unknown(): void
    {
        $this->assertSame(DisplayStatus::Operational, DisplayStatusResolver::overall([DisplayStatus::Operational, DisplayStatus::Unknown]));
        $this->assertSame(DisplayStatus::Degraded, DisplayStatusResolver::overall([DisplayStatus::Unknown, DisplayStatus::Degraded, DisplayStatus::Maintenance]));
        $this->assertSame(DisplayStatus::Maintenance, DisplayStatusResolver::overall([DisplayStatus::Operational, DisplayStatus::Maintenance]));
        $this->assertSame(DisplayStatus::Unknown, DisplayStatusResolver::overall([DisplayStatus::Unknown, DisplayStatus::Unknown]));
        $this->assertSame(DisplayStatus::Operational, DisplayStatusResolver::overall([]));
    }

    public function test_label_keys(): void
    {
        $this->assertSame('all_operational', DisplayStatusResolver::labelKey(DisplayStatus::Operational));
        $this->assertSame('degraded', DisplayStatusResolver::labelKey(DisplayStatus::Degraded));
        $this->assertSame('partial_outage', DisplayStatusResolver::labelKey(DisplayStatus::PartialOutage));
        $this->assertSame('major_outage', DisplayStatusResolver::labelKey(DisplayStatus::MajorOutage));
        $this->assertSame('maintenance', DisplayStatusResolver::labelKey(DisplayStatus::Maintenance));
        $this->assertSame('unknown', DisplayStatusResolver::labelKey(DisplayStatus::Unknown));
    }

    public function test_day_bar_rules(): void
    {
        $empty = DayBar::fromCounts('2026-11-07', 0, 0, 0, 0, 0);
        $this->assertSame(DisplayStatus::Unknown, $empty->status);
        $this->assertNull($empty->uptimePercent);
        $this->assertSame(0, $empty->downMinutesApprox);

        $this->assertSame(DisplayStatus::Unknown, DayBar::fromCounts('2026-11-07', 0, 0, 0, 5, 0)->status);
        $this->assertSame(DisplayStatus::Maintenance, DayBar::fromCounts('2026-11-07', 0, 0, 0, 2, 60)->status);
        $this->assertSame(DisplayStatus::Operational, DayBar::fromCounts('2026-11-07', 1440, 0, 0, 0, 0)->status);
        $this->assertSame(DisplayStatus::Operational, DayBar::fromCounts('2026-11-07', 100, 0, 0, 0, 30)->status);
        $this->assertSame(DisplayStatus::Degraded, DayBar::fromCounts('2026-11-07', 1400, 40, 0, 0, 0)->status);

        $partial = DayBar::fromCounts('2026-11-07', 1430, 0, 10, 0, 0);
        $this->assertSame(DisplayStatus::PartialOutage, $partial->status);
        $this->assertSame(99.31, $partial->uptimePercent);
        $this->assertSame(10, $partial->downMinutesApprox);

        $major = DayBar::fromCounts('2026-11-07', 1000, 0, 440, 0, 0);
        $this->assertSame(DisplayStatus::MajorOutage, $major->status);
        $this->assertSame(69.44, $major->uptimePercent);
        $this->assertSame(440, $major->downMinutesApprox);
    }

    public function test_uptime_never_rounds_up_to_a_perfect_score(): void
    {
        $this->assertSame(99.99, DisplayStatusResolver::uptimePercent(525_599, 1));
        $this->assertSame(100.0, DisplayStatusResolver::uptimePercent(10, 0));
        $this->assertNull(DisplayStatusResolver::uptimePercent(0, 0));
    }
}
