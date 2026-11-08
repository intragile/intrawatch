<?php

declare(strict_types=1);

namespace Tests\Unit\Import\Kuma;

use App\Import\Kuma\Mapping\IncidentMapper;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class IncidentMapperTest extends TestCase
{
    private const NOW = '2026-11-10 12:00:00';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function map(array $overrides = []): array
    {
        $row = array_replace([
            'id' => 1, 'title' => 'Degraded API', 'content' => 'Investigating', 'style' => 'warning',
            'created_date' => '2026-11-07 22:32:33', 'last_updated_date' => null, 'pin' => 1, 'active' => 1, 'status_page_id' => 1,
        ], $overrides);

        return (new IncidentMapper)->map($row, CarbonImmutable::parse(self::NOW, 'UTC'))->attributes;
    }

    public function test_pinned_active_incident_is_open(): void
    {
        $attributes = $this->map();

        $this->assertSame('investigating', $attributes['status']);
        $this->assertSame('minor', $attributes['impact']);
        $this->assertSame('Investigating', $attributes['message']);
        $this->assertSame('2026-11-07T22:32:33Z', $attributes['started_at']);
        $this->assertArrayNotHasKey('resolved_at', $attributes);
    }

    public function test_unpinned_or_inactive_incident_is_resolved_at_its_last_update(): void
    {
        foreach ([['pin' => 0], ['active' => 0]] as $override) {
            $attributes = $this->map($override + ['last_updated_date' => '2026-11-08 10:00:00']);

            $this->assertSame('resolved', $attributes['status']);
            $this->assertSame('2026-11-08T10:00:00Z', $attributes['resolved_at']);
        }
    }

    public function test_resolved_without_update_date_ends_at_creation(): void
    {
        $attributes = $this->map(['pin' => 0]);

        $this->assertSame('2026-11-07T22:32:33Z', $attributes['resolved_at']);
    }

    public function test_impact_follows_the_style(): void
    {
        $this->assertSame('major', $this->map(['style' => 'danger'])['impact']);
        $this->assertSame('minor', $this->map(['style' => 'warning'])['impact']);
        foreach (['info', 'primary', 'light', 'dark', null] as $style) {
            $this->assertSame('none', $this->map(['style' => $style])['impact']);
        }
    }

    public function test_future_dates_are_clamped_to_now_and_empty_content_uses_the_title(): void
    {
        $attributes = $this->map(['created_date' => '2030-01-01 00:00:00', 'content' => '  ']);

        $this->assertSame('2026-11-10T12:00:00Z', $attributes['started_at']);
        $this->assertSame('Degraded API', $attributes['message']);
    }
}
