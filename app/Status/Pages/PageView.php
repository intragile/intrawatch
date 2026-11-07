<?php

namespace App\Status\Pages;

use App\Enums\DisplayStatus;
use Carbon\CarbonImmutable;

/**
 * Everything a public status page shows, computed at one instant.
 */
final readonly class PageView
{
    /**
     * @param  list<SectionView>  $sections
     * @param  list<IncidentView>  $openIncidents  Newest start first.
     * @param  list<IncidentView>  $pastIncidents  Resolved within the page's history, most recently resolved first.
     * @param  list<MaintenanceView>  $activeMaintenance  Ordered by start.
     * @param  list<MaintenanceView>  $upcomingMaintenance  Ordered by start.
     * @param  list<MaintenanceView>  $recentMaintenance  Windows that ended within the last 30 days, ordered by start; filled only for feeds.
     * @param  CarbonImmutable|null  $expiresAt  The next maintenance start or end within the cache freshness, after which the view is stale.
     */
    public function __construct(
        public string $title,
        public ?string $descriptionHtml,
        public DisplayStatus $overall,
        public string $overallLabelKey,
        public array $sections,
        public array $openIncidents,
        public array $pastIncidents,
        public array $activeMaintenance,
        public array $upcomingMaintenance,
        public ?CarbonImmutable $expiresAt,
        public CarbonImmutable $generatedAt,
        public string $timezone,
        public string $locale,
        public array $recentMaintenance = [],
    ) {}
}
