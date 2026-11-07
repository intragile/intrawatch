<?php

namespace Tests\Unit\Status;

use App\Enums\DisplayStatus;
use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use PHPUnit\Framework\TestCase;

class EnumTest extends TestCase
{
    public function test_display_status_ordering(): void
    {
        $this->assertSame(DisplayStatus::MajorOutage, DisplayStatus::worst(DisplayStatus::Operational, DisplayStatus::MajorOutage, DisplayStatus::Maintenance));
        $this->assertSame(DisplayStatus::Maintenance, DisplayStatus::worst(DisplayStatus::Operational, DisplayStatus::Maintenance));
        $this->assertSame(DisplayStatus::Unknown, DisplayStatus::worst(DisplayStatus::Operational, DisplayStatus::Unknown));
        $this->assertSame(DisplayStatus::Operational, DisplayStatus::worst());
        $this->assertSame(DisplayStatus::PartialOutage, IncidentImpact::Major->displayStatus());
    }

    public function test_impact_maps_to_display_status(): void
    {
        $this->assertSame(DisplayStatus::Operational, IncidentImpact::None->displayStatus());
        $this->assertSame(DisplayStatus::Degraded, IncidentImpact::Minor->displayStatus());
        $this->assertSame(DisplayStatus::MajorOutage, IncidentImpact::Critical->displayStatus());
    }

    public function test_only_resolved_is_closed(): void
    {
        $this->assertTrue(IncidentStatus::Investigating->isOpen());
        $this->assertTrue(IncidentStatus::Identified->isOpen());
        $this->assertTrue(IncidentStatus::Monitoring->isOpen());
        $this->assertFalse(IncidentStatus::Resolved->isOpen());
    }
}
