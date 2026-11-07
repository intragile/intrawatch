<?php

namespace App\Status\Pages;

use App\Enums\IncidentStatus;
use Carbon\CarbonImmutable;

/**
 * One entry of an incident timeline, with its message rendered to safe HTML.
 */
final readonly class IncidentUpdateView
{
    public function __construct(
        public IncidentStatus $status,
        public string $messageHtml,
        public CarbonImmutable $createdAt,
    ) {}
}
