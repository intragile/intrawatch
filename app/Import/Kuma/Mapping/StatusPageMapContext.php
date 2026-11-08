<?php

declare(strict_types=1);

namespace App\Import\Kuma\Mapping;

use Closure;

/**
 * What the status page mapper needs beyond the row itself: the instance's groups, group members and domains
 * keyed by their page or group, whether a slug is already in use, and how a Kuma monitor id resolves to an
 * IntraWatch monitor id.
 */
final readonly class StatusPageMapContext
{
    /**
     * @param  array<int, list<array<string, mixed>>>  $groups  Kuma `group` rows by status page id.
     * @param  array<int, list<array<string, mixed>>>  $items  Kuma `monitor_group` rows by group id.
     * @param  array<int, list<string>>  $domains  Kuma `status_page_cname` domains by status page id.
     * @param  Closure(string): bool  $slugTaken  Whether the slug is in use, here or in this run.
     * @param  Closure(int): ?int  $resolveMonitor  Kuma monitor id to monitor id; null when it was not imported.
     */
    public function __construct(
        public array $groups,
        public array $items,
        public array $domains,
        public Closure $slugTaken,
        public Closure $resolveMonitor,
    ) {}
}
