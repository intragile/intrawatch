<?php

namespace App\Status\Pages;

use App\Enums\DisplayStatus;
use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\CheckRollup;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Maintenance;
use App\Models\StatusPage;
use App\Models\Workspace;
use App\Monitoring\Types\GroupHierarchy;
use App\Status\Maintenance\MaintenanceSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Builds the read model of one public status page from monitor states, rollups, incidents and maintenance.
 *
 * The number of queries does not depend on the number of components: the layout, monitors and their states
 * come from one join, and every per-monitor lookup (expanded children, child counts, day and hour rollups,
 * ancestors, incidents, maintenance) is one query per 500 monitors. With up to 500 rendered monitors a page
 * costs at most eleven queries. Raw check results are never read.
 */
final class StatusPageSnapshot
{
    /** Most monitors one page renders, items and expanded children together. */
    public const MAX_MONITORS = 1000;

    private const CHUNK = 500;

    private const MARKDOWN = ['html_input' => 'escape', 'allow_unsafe_links' => false];

    /**
     * @param  int  $maxMonitors  Rendered monitor cap; children beyond it are dropped, items never are.
     */
    public function __construct(private readonly int $maxMonitors = self::MAX_MONITORS) {}

    public function build(StatusPage $page, CarbonImmutable $now): PageView
    {
        $timezone = $this->timezone($page);
        $layout = $this->layout($page);

        // Monitor rows by id for everything rendered; item monitors first, then expanded children up to the cap.
        $monitors = $layout['monitors'];
        $expandable = [];
        foreach ($layout['sections'] as $section) {
            foreach ($section['items'] as $item) {
                if ($item['expand'] && $monitors[$item['monitor_id']]['type'] === MonitorType::Group->value) {
                    $expandable[$item['monitor_id']] = true;
                }
            }
        }
        $childrenOf = [];
        foreach ($this->children($page->workspace_id, array_keys($expandable)) as $parentId => $children) {
            foreach ($children as $child) {
                if (! isset($monitors[$child['id']])) {
                    if (count($monitors) >= $this->maxMonitors) {
                        continue;
                    }
                    $monitors[$child['id']] = $child;
                }
                $childrenOf[$parentId][] = $child['id'];
            }
        }

        $displayed = array_keys($monitors);
        $groupIds = array_keys(array_filter($monitors, static fn (array $m): bool => $m['type'] === MonitorType::Group->value));
        $childCounts = $this->childCounts($groupIds);

        $today = $now->setTimezone($timezone)->startOfDay();
        $barDays = max(1, (int) $page->bar_days);
        $uptimeDays = max(1, (int) $page->uptime_days);
        $dayRows = $this->dayRows($displayed, $today->subDays($barDays - 1), $today->addDay(), $timezone);
        $uptime = $uptimeDays <= $barDays
            ? $this->uptimeFromRows($dayRows, $today, $uptimeDays)
            : $this->uptimeFromDatabase($displayed, $today->subDays($uptimeDays - 1), $today->addDay());
        $latency = $page->show_latency ? $this->latency($displayed, $now) : null;

        $ancestors = $this->ancestors(array_keys(array_filter($monitors, static fn (array $m): bool => $m['parent_id'] !== null)));

        // Display names of every rendered occurrence in page order, for the affected-component lists.
        $occurrences = [];
        foreach ($layout['sections'] as $section) {
            foreach ($section['items'] as $item) {
                $occurrences[] = [$item['monitor_id'], $item['name']];
                foreach ($item['expand'] ? ($childrenOf[$item['monitor_id']] ?? []) : [] as $childId) {
                    $occurrences[] = [$childId, $monitors[$childId]['name']];
                }
            }
        }

        $incidents = $this->incidents($page, $displayed, $now, $occurrences);
        $maintenance = $this->maintenance($page, $displayed, $ancestors, $now, $timezone, $occurrences);

        $status = static fn (int $monitorId): DisplayStatus => DisplayStatusResolver::item(
            $monitors[$monitorId]['status'],
            $monitors[$monitorId]['type'] === MonitorType::Group->value ? ($childCounts[$monitorId] ?? []) : null,
            isset($maintenance['covered'][$monitorId]),
            $incidents['impacts'][$monitorId] ?? [],
        );
        $hourly = static fn (int $monitorId): ?array => $latency === null ? null : ($latency[$monitorId] ?? array_fill(0, 24, null));

        $sections = [];
        $statuses = [];
        foreach ($layout['sections'] as $section) {
            $items = [];
            foreach ($section['items'] as $item) {
                $monitorId = $item['monitor_id'];
                $children = [];
                foreach ($item['expand'] ? ($childrenOf[$monitorId] ?? []) : [] as $childId) {
                    $children[] = new ItemView(
                        $childId,
                        $monitors[$childId]['name'],
                        null,
                        $status($childId),
                        $item['show_uptime'] ? ($uptime[$childId] ?? null) : null,
                        $this->bars($dayRows[$childId] ?? [], $today, $barDays),
                        $hourly($childId),
                        [],
                    );
                }
                $view = new ItemView(
                    $monitorId,
                    $item['name'],
                    $item['description'],
                    $status($monitorId),
                    $item['show_uptime'] ? ($uptime[$monitorId] ?? null) : null,
                    $this->bars($dayRows[$monitorId] ?? [], $today, $barDays),
                    $hourly($monitorId),
                    $children,
                );
                $items[] = $view;
                $statuses[] = $view->status;
            }
            $sections[] = new SectionView($section['id'], $section['name'], $section['description'], $items);
        }

        // Every open incident shown on the page counts, including page-wide ones that name no monitor.
        foreach ($incidents['open'] as $incident) {
            $statuses[] = $incident->impact->displayStatus();
        }
        $overall = DisplayStatusResolver::overall($statuses);

        return new PageView(
            title: $page->title,
            descriptionHtml: $this->markdown($page->description),
            overall: $overall,
            overallLabelKey: DisplayStatusResolver::labelKey($overall),
            sections: $sections,
            openIncidents: $incidents['open'],
            pastIncidents: $incidents['past'],
            activeMaintenance: $maintenance['active'],
            upcomingMaintenance: $maintenance['upcoming'],
            expiresAt: $maintenance['expires_at'],
            generatedAt: $now,
            timezone: $timezone,
            locale: $page->locale,
        );
    }

    /**
     * One bar per local day, oldest first, ending with today; a day without a rollup row is unknown.
     *
     * @param  array<string, array{int, int, int, int, int}>  $days  Counts by local date.
     * @return list<DayBar>
     */
    private function bars(array $days, CarbonImmutable $today, int $barDays): array
    {
        $bars = [];
        for ($offset = $barDays - 1; $offset >= 0; $offset--) {
            $date = $today->subDays($offset)->format('Y-m-d');
            $bars[] = DayBar::fromCounts($date, ...($days[$date] ?? [0, 0, 0, 0, 0]));
        }

        return $bars;
    }

    private function timezone(StatusPage $page): string
    {
        $timezone = $page->relationLoaded('workspace') && $page->workspace instanceof Workspace
            ? $page->workspace->timezone
            : DB::table('workspaces')->where('id', $page->workspace_id)->value('timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }

    /**
     * Sections, their items, the item monitors and their states in one query. Items whose monitor is missing
     * or belongs to another workspace are skipped.
     *
     * @return array{
     *     sections: list<array{id: int, name: string, description: string|null, items: list<array{monitor_id: int, name: string, description: string|null, expand: bool, show_uptime: bool}>}>,
     *     monitors: array<int, array{id: int, name: string, type: string, parent_id: int|null, status: MonitorStatus}>
     * }
     */
    private function layout(StatusPage $page): array
    {
        $rows = DB::table('status_page_sections as s')
            ->leftJoin('status_page_items as i', 'i.status_page_section_id', '=', 's.id')
            ->leftJoin('monitors as m', function (JoinClause $join) use ($page): void {
                $join->on('m.id', '=', 'i.monitor_id')->where('m.workspace_id', '=', $page->workspace_id);
            })
            ->leftJoin('monitor_states as st', 'st.monitor_id', '=', 'm.id')
            ->where('s.status_page_id', $page->id)
            ->orderBy('s.position')->orderBy('s.id')->orderBy('i.position')->orderBy('i.id')
            ->get([
                's.id as section_id', 's.name as section_name', 's.description as section_description',
                'i.display_name', 'i.description as item_description', 'i.expand_children', 'i.show_uptime',
                'm.id as monitor_id', 'm.name as monitor_name', 'm.type as monitor_type', 'm.parent_id', 'st.status',
            ]);

        $sections = [];
        $monitors = [];
        foreach ($rows as $row) {
            $sectionId = self::int($row->section_id);
            $sections[$sectionId] ??= [
                'id' => $sectionId,
                'name' => self::string($row->section_name),
                'description' => self::nullableString($row->section_description),
                'items' => [],
            ];
            if ($row->monitor_id === null) {
                continue;
            }
            $monitor = self::monitorRow($row->monitor_id, $row->monitor_name, $row->monitor_type, $row->parent_id, $row->status);
            $monitors[$monitor['id']] ??= $monitor;
            $sections[$sectionId]['items'][] = [
                'monitor_id' => $monitor['id'],
                'name' => self::nullableString($row->display_name) ?? $monitor['name'],
                'description' => self::nullableString($row->item_description),
                'expand' => (bool) $row->expand_children,
                'show_uptime' => (bool) $row->show_uptime,
            ];
        }

        return ['sections' => array_values($sections), 'monitors' => $monitors];
    }

    /**
     * Direct children of the given groups with their states, ordered by name.
     *
     * @param  list<int>  $groupIds
     * @return array<int, list<array{id: int, name: string, type: string, parent_id: int|null, status: MonitorStatus}>>
     */
    private function children(int $workspaceId, array $groupIds): array
    {
        $children = [];
        foreach (array_chunk($groupIds, self::CHUNK) as $chunk) {
            $rows = DB::table('monitors as m')
                ->leftJoin('monitor_states as st', 'st.monitor_id', '=', 'm.id')
                ->where('m.workspace_id', $workspaceId)
                ->whereIn('m.parent_id', $chunk)
                ->orderBy('m.name')->orderBy('m.id')
                ->get(['m.id', 'm.name', 'm.type', 'm.parent_id', 'st.status']);
            foreach ($rows as $row) {
                $child = self::monitorRow($row->id, $row->name, $row->type, $row->parent_id, $row->status);
                $children[(int) $child['parent_id']][] = $child;
            }
        }

        return $children;
    }

    /**
     * Counted children per group and status, with the same rule as the group evaluator: active children
     * only, paused and maintenance children left out, a missing state counted as pending.
     *
     * @param  list<int>  $groupIds
     * @return array<int, array<string, int>>
     */
    private function childCounts(array $groupIds): array
    {
        $counts = [];
        foreach (array_chunk($groupIds, self::CHUNK) as $chunk) {
            $rows = DB::table('monitors')
                ->leftJoin('monitor_states', 'monitor_states.monitor_id', '=', 'monitors.id')
                ->whereIn('monitors.parent_id', $chunk)
                ->where('monitors.active', true)
                ->groupBy('monitors.parent_id', 'monitor_states.status')
                ->get(['monitors.parent_id', 'monitor_states.status', DB::raw('count(*) as total')]);
            foreach ($rows as $row) {
                $status = $row->status === null ? MonitorStatus::Pending->value : self::string($row->status);
                if ($status === MonitorStatus::Paused->value || $status === MonitorStatus::Maintenance->value) {
                    continue;
                }
                $parent = self::int($row->parent_id);
                $counts[$parent][$status] = ($counts[$parent][$status] ?? 0) + self::int($row->total);
            }
        }

        return $counts;
    }

    /**
     * Day rollup counts per monitor and local date in [$from, $to).
     *
     * @param  list<int>  $monitorIds
     * @return array<int, array<string, array{int, int, int, int, int}>> Up, degraded, down, pending, maintenance.
     */
    private function dayRows(array $monitorIds, CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        $days = [];
        foreach (array_chunk($monitorIds, self::CHUNK) as $chunk) {
            $rows = DB::table('check_rollups')
                ->where('bucket', CheckRollup::BUCKET_DAY)
                ->whereIn('monitor_id', $chunk)
                ->where('bucket_start', '>=', $from->getTimestamp())
                ->where('bucket_start', '<', $to->getTimestamp())
                ->get(['monitor_id', 'bucket_start', 'up', 'degraded', 'down', 'pending', 'maintenance']);
            foreach ($rows as $row) {
                $date = CarbonImmutable::createFromTimestamp(self::int($row->bucket_start), $timezone)->format('Y-m-d');
                $days[self::int($row->monitor_id)][$date] = [
                    self::int($row->up), self::int($row->degraded), self::int($row->down), self::int($row->pending), self::int($row->maintenance),
                ];
            }
        }

        return $days;
    }

    /**
     * @param  array<int, array<string, array{int, int, int, int, int}>>  $dayRows
     * @return array<int, float|null>
     */
    private function uptimeFromRows(array $dayRows, CarbonImmutable $today, int $days): array
    {
        $first = $today->subDays($days - 1)->format('Y-m-d');
        $uptime = [];
        foreach ($dayRows as $monitorId => $rows) {
            $available = 0;
            $down = 0;
            foreach ($rows as $date => $row) {
                if ($date >= $first) {
                    $available += $row[0] + $row[1];
                    $down += $row[2];
                }
            }
            $uptime[$monitorId] = DisplayStatusResolver::uptimePercent($available, $down);
        }

        return $uptime;
    }

    /**
     * @param  list<int>  $monitorIds
     * @return array<int, float|null>
     */
    private function uptimeFromDatabase(array $monitorIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $uptime = [];
        foreach (array_chunk($monitorIds, self::CHUNK) as $chunk) {
            $rows = DB::table('check_rollups')
                ->where('bucket', CheckRollup::BUCKET_DAY)
                ->whereIn('monitor_id', $chunk)
                ->where('bucket_start', '>=', $from->getTimestamp())
                ->where('bucket_start', '<', $to->getTimestamp())
                ->groupBy('monitor_id')
                ->get(['monitor_id', DB::raw('sum(up) + sum(degraded) as available'), DB::raw('sum(down) as down_total')]);
            foreach ($rows as $row) {
                $uptime[self::int($row->monitor_id)] = DisplayStatusResolver::uptimePercent(self::int($row->available), self::int($row->down_total));
            }
        }

        return $uptime;
    }

    /**
     * Average latency of the 24 UTC hours ending with the current one, oldest first.
     *
     * @param  list<int>  $monitorIds
     * @return array<int, list<int|null>>
     */
    private function latency(array $monitorIds, CarbonImmutable $now): array
    {
        $last = intdiv($now->getTimestamp(), 3600) * 3600;
        $first = $last - 23 * 3600;
        $averages = [];
        foreach (array_chunk($monitorIds, self::CHUNK) as $chunk) {
            $rows = DB::table('check_rollups')
                ->where('bucket', CheckRollup::BUCKET_HOUR)
                ->whereIn('monitor_id', $chunk)
                ->whereBetween('bucket_start', [$first, $last])
                ->get(['monitor_id', 'bucket_start', 'latency_sum', 'latency_count']);
            foreach ($rows as $row) {
                $count = self::int($row->latency_count);
                if ($count > 0) {
                    $averages[self::int($row->monitor_id)][self::int($row->bucket_start)] = (int) round(self::int($row->latency_sum) / $count);
                }
            }
        }

        $latency = [];
        foreach ($averages as $monitorId => $byHour) {
            $hours = [];
            for ($hour = $first; $hour <= $last; $hour += 3600) {
                $hours[] = $byHour[$hour] ?? null;
            }
            $latency[$monitorId] = $hours;
        }

        return $latency;
    }

    /**
     * Every ancestor group of the given monitors, nearest first, from one recursive query per chunk.
     *
     * @param  list<int>  $monitorIds  Monitors that have a parent.
     * @return array<int, list<int>>
     */
    private function ancestors(array $monitorIds): array
    {
        $ancestors = [];
        foreach (array_chunk($monitorIds, self::CHUNK) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $rows = DB::select(
                'with recursive chain (monitor_id, ancestor_id, depth) as ('
                ."select id, parent_id, 1 from monitors where id in ({$placeholders}) and parent_id is not null "
                .'union all '
                .'select chain.monitor_id, m.parent_id, chain.depth + 1 from chain join monitors m on m.id = chain.ancestor_id '
                .'where m.parent_id is not null and chain.depth < ?'
                .') select monitor_id, ancestor_id, depth from chain order by monitor_id, depth',
                [...$chunk, GroupHierarchy::MAX_DEPTH],
            );
            foreach ($rows as $row) {
                $row = (array) $row;
                $ancestors[self::int($row['monitor_id'] ?? null)][] = self::int($row['ancestor_id'] ?? null);
            }
        }

        return $ancestors;
    }

    /**
     * Incidents linked to the page or naming a rendered monitor: open ones, and resolved ones within the
     * page's history.
     *
     * @param  list<int>  $displayed
     * @param  list<array{int, string}>  $occurrences
     * @return array{open: list<IncidentView>, past: list<IncidentView>, impacts: array<int, list<IncidentImpact>>}
     */
    private function incidents(StatusPage $page, array $displayed, CarbonImmutable $now, array $occurrences): array
    {
        $cutoff = $now->subDays(max(0, (int) $page->incident_history_days));
        $linked = $this->linked($page, 'incident');

        /** @var array<int, Incident> $incidents */
        $incidents = [];
        $affected = [];
        foreach ($displayed === [] ? [[]] : array_chunk($displayed, self::CHUNK) as $chunk) {
            $rows = Incident::query()
                ->leftJoin('incident_monitor', function (JoinClause $join) use ($chunk): void {
                    $join->on('incident_monitor.incident_id', '=', 'incidents.id')->whereIn('incident_monitor.monitor_id', $chunk);
                })
                ->where('incidents.workspace_id', $page->workspace_id)
                ->where(fn ($q) => $q->whereNotNull('incident_monitor.monitor_id')->orWhereIn('incidents.id', $linked))
                ->where(fn ($q) => $q->where('incidents.status', '!=', IncidentStatus::Resolved->value)->orWhere('incidents.resolved_at', '>=', $cutoff))
                ->get(['incidents.*', 'incident_monitor.monitor_id as affected_monitor_id']);
            foreach ($rows as $incident) {
                $incidents[$incident->id] ??= $incident;
                $monitorId = $incident->getAttribute('affected_monitor_id');
                if ($monitorId !== null) {
                    $affected[$incident->id][self::int($monitorId)] = true;
                }
            }
        }

        $updates = [];
        foreach (array_chunk(array_keys($incidents), self::CHUNK) as $chunk) {
            foreach (IncidentUpdate::query()->whereIn('incident_id', $chunk)->orderByDesc('created_at')->orderByDesc('id')->get() as $update) {
                $updates[$update->incident_id][] = new IncidentUpdateView(
                    $update->status,
                    $this->markdown($update->message) ?? '',
                    CarbonImmutable::instance($update->created_at),
                );
            }
        }

        $open = [];
        $past = [];
        $impacts = [];
        foreach ($incidents as $id => $incident) {
            $isOpen = $incident->status->isOpen();
            if ($isOpen) {
                foreach (array_keys($affected[$id] ?? []) as $monitorId) {
                    $impacts[$monitorId][] = $incident->impact;
                }
            }
            $view = new IncidentView(
                $id,
                $incident->title,
                $incident->impact,
                $incident->status,
                CarbonImmutable::instance($incident->started_at),
                $incident->resolved_at === null ? null : CarbonImmutable::instance($incident->resolved_at),
                $updates[$id] ?? [],
                self::names($occurrences, $affected[$id] ?? []),
            );
            if ($isOpen) {
                $open[] = $view;
            } else {
                $past[] = $view;
            }
        }

        usort($open, static fn (IncidentView $a, IncidentView $b): int => [$b->startedAt->getTimestamp(), $b->id] <=> [$a->startedAt->getTimestamp(), $a->id]);
        usort($past, static fn (IncidentView $a, IncidentView $b): int => [$b->resolvedAt?->getTimestamp() ?? 0, $b->id] <=> [$a->resolvedAt?->getTimestamp() ?? 0, $a->id]);

        return ['open' => $open, 'past' => $past, 'impacts' => $impacts];
    }

    /**
     * Enabled maintenances linked to the page or targeting a rendered monitor or one of its ancestor groups,
     * split into those in progress and those whose next window starts within the page's horizon.
     *
     * @param  list<int>  $displayed
     * @param  array<int, list<int>>  $ancestors
     * @param  list<array{int, string}>  $occurrences
     * @return array{active: list<MaintenanceView>, upcoming: list<MaintenanceView>, covered: array<int, true>, expires_at: CarbonImmutable|null}
     */
    private function maintenance(StatusPage $page, array $displayed, array $ancestors, CarbonImmutable $now, string $timezone, array $occurrences): array
    {
        $relevant = array_values(array_unique(array_merge($displayed, ...array_values($ancestors))));
        $linked = $this->linked($page, 'maintenance');

        /** @var array<int, Maintenance> $maintenances */
        $maintenances = [];
        $targets = [];
        foreach ($relevant === [] ? [[]] : array_chunk($relevant, self::CHUNK) as $chunk) {
            $rows = Maintenance::query()
                ->leftJoin('maintenance_monitor', function (JoinClause $join) use ($chunk): void {
                    $join->on('maintenance_monitor.maintenance_id', '=', 'maintenances.id')->whereIn('maintenance_monitor.monitor_id', $chunk);
                })
                ->where('maintenances.workspace_id', $page->workspace_id)
                ->where('maintenances.active', true)
                ->where(fn ($q) => $q->whereNotNull('maintenance_monitor.monitor_id')->orWhereIn('maintenances.id', $linked))
                ->get(['maintenances.*', 'maintenance_monitor.monitor_id as target_monitor_id']);
            foreach ($rows as $maintenance) {
                $maintenances[$maintenance->id] ??= $maintenance;
                $monitorId = $maintenance->getAttribute('target_monitor_id');
                if ($monitorId !== null) {
                    $targets[$maintenance->id][self::int($monitorId)] = true;
                }
            }
        }

        $horizon = $now->addDays(max(0, (int) $page->upcoming_maintenance_days));
        $expiryLimit = $now->addSeconds(max(1, config()->integer('status.cache.ttl_seconds', 60)));
        $active = [];
        $upcoming = [];
        $covered = [];
        $expiresAt = null;
        foreach ($maintenances as $id => $maintenance) {
            try {
                $schedule = MaintenanceSchedule::fromModel($maintenance, $timezone);
            } catch (InvalidArgumentException) {
                continue;
            }

            foreach ($schedule->windowsBetween($now, $expiryLimit->addSecond()) as $window) {
                foreach ([$window->start, $window->end] as $boundary) {
                    if ($boundary !== null && $boundary->gt($now) && $boundary->lte($expiryLimit) && ($expiresAt === null || $boundary->lt($expiresAt))) {
                        $expiresAt = $boundary;
                    }
                }
            }

            $window = $schedule->nextWindow($now);
            if ($window === null) {
                continue;
            }
            $inProgress = $window->contains($now);
            if (! $inProgress && $window->start->gt($horizon)) {
                continue;
            }

            $coveredHere = [];
            foreach ($displayed as $monitorId) {
                foreach ([$monitorId, ...($ancestors[$monitorId] ?? [])] as $candidate) {
                    if (isset($targets[$id][$candidate])) {
                        $coveredHere[$monitorId] = true;
                        break;
                    }
                }
            }

            $view = new MaintenanceView(
                $id,
                $maintenance->title,
                $this->markdown($maintenance->description),
                $window->start,
                $window->end,
                self::names($occurrences, $coveredHere),
            );
            if ($inProgress) {
                $active[] = $view;
                $covered += $coveredHere;
            } else {
                $upcoming[] = $view;
            }
        }

        $byStart = static fn (MaintenanceView $a, MaintenanceView $b): int => [$a->startsAt->getTimestamp(), $a->id] <=> [$b->startsAt->getTimestamp(), $b->id];
        usort($active, $byStart);
        usort($upcoming, $byStart);

        return ['active' => $active, 'upcoming' => $upcoming, 'covered' => $covered, 'expires_at' => $expiresAt];
    }

    /**
     * Ids of the incidents or maintenances explicitly linked to the page, as a subquery.
     */
    private function linked(StatusPage $page, string $type): Builder
    {
        return DB::table('status_page_links')
            ->select('linkable_id')
            ->where('status_page_id', $page->id)
            ->where('linkable_type', $type);
    }

    /**
     * Distinct display names of the occurrences whose monitor is in the set, in page order.
     *
     * @param  list<array{int, string}>  $occurrences
     * @param  array<int, true>  $monitorIds
     * @return list<string>
     */
    private static function names(array $occurrences, array $monitorIds): array
    {
        $names = [];
        foreach ($occurrences as [$monitorId, $name]) {
            if (isset($monitorIds[$monitorId]) && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function markdown(?string $text): ?string
    {
        return $text === null || $text === '' ? null : Str::markdown($text, self::MARKDOWN);
    }

    /**
     * @return array{id: int, name: string, type: string, parent_id: int|null, status: MonitorStatus}
     */
    private static function monitorRow(mixed $id, mixed $name, mixed $type, mixed $parentId, mixed $status): array
    {
        return [
            'id' => self::int($id),
            'name' => self::string($name),
            'type' => self::string($type),
            'parent_id' => $parentId === null ? null : self::int($parentId),
            'status' => MonitorStatus::tryFrom(self::string($status)) ?? MonitorStatus::Pending,
        ];
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
