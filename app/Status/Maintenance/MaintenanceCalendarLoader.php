<?php

namespace App\Status\Maintenance;

use App\Models\Maintenance;
use App\Monitoring\Types\GroupHierarchy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds the maintenance calendar from the database: active maintenances, their targets expanded to the
 * descendants of every targeted group, and their windows merged per monitor.
 */
class MaintenanceCalendarLoader
{
    private const CHUNK = 500;

    public function __construct(
        private readonly int $pastHours = 26,
        private readonly int $futureHours = 50,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config()->integer('status.maintenance.horizon_hours.past', 26),
            config()->integer('status.maintenance.horizon_hours.future', 50),
        );
    }

    public function load(CarbonImmutable $now): MaintenanceCalendar
    {
        $from = $now->subHours($this->pastHours);
        $to = $now->addHours($this->futureHours);

        /** @var array<int, list<Window>> $windowsByMaintenance */
        $windowsByMaintenance = [];
        $zones = DB::table('workspaces')->pluck('timezone', 'id');

        Maintenance::query()->where('active', true)->orderBy('id')->chunkById(self::CHUNK, function ($maintenances) use ($zones, $from, $to, &$windowsByMaintenance): void {
            foreach ($maintenances as $maintenance) {
                $zone = $zones[$maintenance->workspace_id] ?? 'UTC';
                $windows = MaintenanceSchedule::fromModel($maintenance, is_string($zone) ? $zone : 'UTC')->windowsBetween($from, $to);
                if ($windows !== []) {
                    // Merged once here; every target then shares this list.
                    $windowsByMaintenance[$maintenance->id] = $this->merge($windows);
                }
            }
        });

        if ($windowsByMaintenance === []) {
            return new MaintenanceCalendar($from, $to, []);
        }

        /** @var array<int, list<int>> $targets */
        $targets = [];
        foreach (array_chunk(array_keys($windowsByMaintenance), self::CHUNK) as $ids) {
            foreach (DB::table('maintenance_monitor')->whereIn('maintenance_id', $ids)->get(['maintenance_id', 'monitor_id']) as $row) {
                $targets[self::int($row->maintenance_id)][] = self::int($row->monitor_id);
            }
        }

        $children = $this->childrenOf(array_values(array_unique(array_merge([], ...array_values($targets)))));

        /** @var array<int, list<int>> $maintenancesByMonitor */
        $maintenancesByMonitor = [];
        foreach ($targets as $maintenanceId => $monitorIds) {
            foreach ($this->expand($monitorIds, $children) as $monitorId) {
                $maintenancesByMonitor[$monitorId][] = $maintenanceId;
            }
        }

        /** @var array<int, list<Window>> $byMonitor */
        $byMonitor = [];
        foreach ($maintenancesByMonitor as $monitorId => $maintenanceIds) {
            if (count($maintenanceIds) === 1) {
                $byMonitor[$monitorId] = $windowsByMaintenance[$maintenanceIds[0]];

                continue;
            }
            $windows = [];
            foreach ($maintenanceIds as $maintenanceId) {
                array_push($windows, ...$windowsByMaintenance[$maintenanceId]);
            }
            $byMonitor[$monitorId] = $this->merge($windows);
        }

        return new MaintenanceCalendar($from, $to, $byMonitor);
    }

    /**
     * Changes whenever a maintenance is created, edited or deleted, or a monitor changes (a reparented
     * monitor changes a group expansion).
     */
    public function stamp(): string
    {
        $maintenances = DB::table('maintenances')->selectRaw('count(*) as total, max(id) as last_id, max(updated_at) as last_update')->first();
        $monitors = DB::table('monitors')->selectRaw('max(updated_at) as last_update')->first();

        return implode('|', [
            self::text($maintenances->total ?? null),
            self::text($maintenances->last_id ?? null),
            self::text($maintenances->last_update ?? null),
            self::text($monitors->last_update ?? null),
        ]);
    }

    /**
     * Child ids by parent id for every descendant of the given monitors, one query per level.
     *
     * @param  list<int>  $roots
     * @return array<int, list<int>>
     */
    private function childrenOf(array $roots): array
    {
        $children = [];
        $seen = array_fill_keys($roots, true);
        $frontier = $roots;
        for ($level = 0; $level < GroupHierarchy::MAX_DEPTH && $frontier !== []; $level++) {
            $next = [];
            foreach (array_chunk($frontier, self::CHUNK) as $ids) {
                foreach (DB::table('monitors')->whereIn('parent_id', $ids)->get(['id', 'parent_id']) as $row) {
                    $id = self::int($row->id);
                    $children[self::int($row->parent_id)][] = $id;
                    if (! isset($seen[$id])) {
                        $seen[$id] = true;
                        $next[] = $id;
                    }
                }
            }
            $frontier = $next;
        }

        return $children;
    }

    /**
     * @param  list<int>  $monitorIds
     * @param  array<int, list<int>>  $children
     * @return list<int> The targets and all their descendants.
     */
    private function expand(array $monitorIds, array $children): array
    {
        $all = [];
        $stack = $monitorIds;
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($all[$id])) {
                continue;
            }
            $all[$id] = true;
            array_push($stack, ...($children[$id] ?? []));
        }

        return array_keys($all);
    }

    /**
     * Union of overlapping and touching windows.
     *
     * @param  list<Window>  $windows
     * @return list<Window>
     */
    private function merge(array $windows): array
    {
        usort($windows, fn (Window $a, Window $b): int => $a->start->getTimestamp() <=> $b->start->getTimestamp());

        $merged = [];
        foreach ($windows as $window) {
            $last = $merged === [] ? null : $merged[array_key_last($merged)];
            if ($last === null || ($last->end !== null && $window->start->getTimestamp() > $last->end->getTimestamp())) {
                $merged[] = $window;

                continue;
            }
            // Overlapping or touching: extend the previous window.
            if ($last->end !== null && ($window->end === null || $window->end->getTimestamp() > $last->end->getTimestamp())) {
                $merged[array_key_last($merged)] = new Window($last->start, $window->end);
            }
        }

        return $merged;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
