<?php

namespace App\Status\Pages;

use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use Carbon\CarbonImmutable;

/**
 * An incident as shown on a page.
 */
final readonly class IncidentView
{
    /**
     * @param  list<IncidentUpdateView>  $updates  Newest first.
     * @param  list<string>  $affectedComponents  Names of the affected components shown on the page, in page order.
     */
    public function __construct(
        public int $id,
        public string $title,
        public IncidentImpact $impact,
        public IncidentStatus $status,
        public CarbonImmutable $startedAt,
        public ?CarbonImmutable $resolvedAt,
        public array $updates,
        public array $affectedComponents,
    ) {}
}
