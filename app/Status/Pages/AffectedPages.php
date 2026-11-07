<?php

namespace App\Status\Pages;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Finds the status pages whose content depends on a set of monitors or on a linked incident or maintenance.
 */
final class AffectedPages
{
    private const CHUNK = 500;

    /**
     * Pages with an item for one of the monitors, or an expanded item for the parent of one of them.
     *
     * @param  list<int>  $monitorIds
     * @return list<int>
     */
    public static function showingMonitors(array $monitorIds): array
    {
        $pages = [];
        foreach (array_chunk(array_values(array_unique($monitorIds)), self::CHUNK) as $chunk) {
            $rows = DB::table('status_page_items')
                ->join('status_page_sections', 'status_page_sections.id', '=', 'status_page_items.status_page_section_id')
                ->where(function (Builder $q) use ($chunk): void {
                    $q->whereIn('status_page_items.monitor_id', $chunk)
                        ->orWhere(function (Builder $q) use ($chunk): void {
                            $q->where('status_page_items.expand_children', true)
                                ->whereIn('status_page_items.monitor_id', DB::table('monitors')->select('parent_id')->whereIn('id', $chunk)->whereNotNull('parent_id'));
                        });
                })
                ->distinct()
                ->pluck('status_page_sections.status_page_id');
            foreach ($rows as $id) {
                $pages[(int) (is_numeric($id) ? $id : 0)] = true;
            }
        }

        return array_keys($pages);
    }

    /**
     * Pages linked to the incident (`incident`) or maintenance (`maintenance`).
     *
     * @return list<int>
     */
    public static function linkedTo(string $type, int $id): array
    {
        return array_values(DB::table('status_page_links')->where('linkable_type', $type)->where('linkable_id', $id)->pluck('status_page_id')
            ->map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0)->all());
    }

    /**
     * Everything a write to the incident can change on a page: its linked pages and the pages showing its monitors.
     *
     * @return list<int>
     */
    public static function forIncident(int $id): array
    {
        return self::forLinkable('incident', 'incident_monitor', 'incident_id', $id);
    }

    /**
     * @return list<int>
     */
    public static function forMaintenance(int $id): array
    {
        return self::forLinkable('maintenance', 'maintenance_monitor', 'maintenance_id', $id);
    }

    /**
     * @return list<int>
     */
    private static function forLinkable(string $type, string $pivot, string $column, int $id): array
    {
        $monitorIds = array_values(DB::table($pivot)->where($column, $id)->pluck('monitor_id')
            ->map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0)->all());

        return array_values(array_unique([...self::linkedTo($type, $id), ...self::showingMonitors($monitorIds)]));
    }
}
