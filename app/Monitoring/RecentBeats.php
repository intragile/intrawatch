<?php

namespace App\Monitoring;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The newest check results of several monitors at once, for the heartbeat strips of the monitor list.
 */
final class RecentBeats
{
    public const MAX = 50;

    /**
     * One query: a union of per-monitor "newest N" lookups, each served by the (monitor_id, checked_at) index.
     *
     * @param  list<int>  $monitorIds
     * @return array<int, list<array{status: string, latency_ms: int|null, checked_at: string}>> Oldest first per monitor.
     */
    public static function for(array $monitorIds, int $count): array
    {
        $count = max(1, min(self::MAX, $count));
        $ids = array_values(array_unique($monitorIds));
        if ($ids === []) {
            return [];
        }

        /** @var Builder|null $union */
        $union = null;
        foreach ($ids as $id) {
            $part = DB::table('check_results')
                ->select(['monitor_id', 'status', 'latency_ms', 'checked_at', 'id'])
                ->where('monitor_id', $id)
                ->orderByDesc('checked_at')
                ->orderByDesc('id')
                ->limit($count);
            $union = $union === null ? $part : $union->unionAll($part);
        }

        $beats = array_fill_keys($ids, []);
        foreach ($union->get() as $row) {
            /** @var object{monitor_id: int|string, status: string, latency_ms: int|string|null, checked_at: string, id: int|string} $row */
            $beats[(int) $row->monitor_id][] = [
                'status' => $row->status,
                'latency_ms' => $row->latency_ms === null ? null : (int) $row->latency_ms,
                'checked_at' => Carbon::parse($row->checked_at)->toIso8601String(),
                '_id' => (int) $row->id,
            ];
        }

        $result = [];
        foreach ($beats as $id => $list) {
            usort($list, static fn (array $a, array $b): int => [$a['checked_at'], $a['_id']] <=> [$b['checked_at'], $b['_id']]);
            $result[$id] = array_map(
                static fn (array $b): array => ['status' => $b['status'], 'latency_ms' => $b['latency_ms'], 'checked_at' => $b['checked_at']],
                $list,
            );
        }

        return $result;
    }
}
