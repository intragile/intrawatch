<?php

namespace App\Status\Pages;

use Carbon\CarbonImmutable;

/**
 * The current or next window of a maintenance as shown on a page.
 */
final readonly class MaintenanceView
{
    /**
     * @param  CarbonImmutable|null  $endsAt  Null for an open-ended window.
     * @param  list<string>  $affectedComponents  Names of the covered components shown on the page, in page order.
     */
    public function __construct(
        public int $id,
        public string $title,
        public ?string $descriptionHtml,
        public CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
        public array $affectedComponents,
    ) {}
}
