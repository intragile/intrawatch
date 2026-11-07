<?php

namespace App\Status\Pages;

use App\Enums\DisplayStatus;

/**
 * One component of a status page, or one expanded child of a group component.
 */
final readonly class ItemView
{
    /**
     * @param  list<DayBar>  $bars  Oldest first, ending with the current local day.
     * @param  list<int|null>|null  $latencyHourly  Average latency in ms of the last 24 UTC hours, oldest first; null when the page hides latency.
     * @param  list<ItemView>  $children  Direct children of an expanded group, ordered by name.
     */
    public function __construct(
        public int $monitorId,
        public string $name,
        public ?string $description,
        public DisplayStatus $status,
        public ?float $uptimePercent,
        public array $bars,
        public ?array $latencyHourly,
        public array $children,
    ) {}
}
