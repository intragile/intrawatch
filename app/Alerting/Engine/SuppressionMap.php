<?php

namespace App\Alerting\Engine;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Monitoring\Types\GroupHierarchy;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Finds the ancestor group whose outage holds back a monitor's alerts: the nearest group above it that is down
 * and has at least one active notification channel, so its own alert already tells users about the outage.
 */
final class SuppressionMap
{
    /**
     * At most three queries, whatever the number of monitors: the monitors' parents together with every group
     * of their workspaces, the groups among those that are down, and which of those have an active channel. The
     * parent chain is then walked in memory.
     *
     * @param  list<int>  $monitorIds
     * @return array<int, ?int> Monitor id => id of the suppressing group, or null.
     */
    public function for(array $monitorIds): array
    {
        if ($monitorIds === []) {
            return [];
        }

        $workspaces = DB::table('monitors')->select('workspace_id')->whereIn('id', $monitorIds);

        /** @var array<int, ?int> $parents */
        $parents = [];
        /** @var array<int, true> $groups */
        $groups = [];
        $rows = DB::table('monitors')
            ->whereIn('id', $monitorIds)
            ->orWhere(fn (Builder $query) => $query->where('type', MonitorType::Group->value)->whereIn('workspace_id', $workspaces))
            ->get(['id', 'parent_id', 'type']);
        foreach ($rows as $row) {
            $id = self::int($row->id);
            $parents[$id] = $row->parent_id === null ? null : self::int($row->parent_id);
            if ($row->type === MonitorType::Group->value) {
                $groups[$id] = true;
            }
        }

        $result = array_fill_keys($monitorIds, null);
        if ($groups === []) {
            return $result;
        }

        $down = [];
        $rows = DB::table('monitor_states')
            ->join('monitors', 'monitors.id', '=', 'monitor_states.monitor_id')
            ->where('monitors.type', MonitorType::Group->value)
            ->whereIn('monitors.workspace_id', $workspaces)
            ->where('monitor_states.status', MonitorStatus::Down->value)
            ->pluck('monitor_states.monitor_id');
        foreach ($rows as $id) {
            $down[] = self::int($id);
        }
        if ($down === []) {
            return $result;
        }

        $alerting = [];
        foreach (array_chunk($down, 500) as $chunk) {
            $rows = DB::table('monitor_notification')
                ->join('notification_channels', 'notification_channels.id', '=', 'monitor_notification.notification_channel_id')
                ->whereIn('monitor_notification.monitor_id', $chunk)
                ->where('notification_channels.active', true)
                ->distinct()
                ->pluck('monitor_notification.monitor_id');
            foreach ($rows as $id) {
                $alerting[self::int($id)] = true;
            }
        }

        foreach ($monitorIds as $monitorId) {
            $parent = $parents[$monitorId] ?? null;
            for ($depth = 0; $depth < GroupHierarchy::MAX_DEPTH && $parent !== null; $depth++) {
                if (isset($alerting[$parent])) {
                    $result[$monitorId] = $parent;
                    break;
                }
                $parent = $parents[$parent] ?? null;
            }
        }

        return $result;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
