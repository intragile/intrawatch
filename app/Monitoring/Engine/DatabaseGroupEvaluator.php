<?php

namespace App\Monitoring\Engine;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\MonitorEvent;
use App\Monitoring\Types\GroupHierarchy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Derives group statuses from the persisted states of their children and walks up the group chain.
 *
 * A group's result row is written when its status changes, or when its last result is older than the group's
 * interval, so rollups see roughly one sample per interval even though recomputes happen on every flush.
 */
final class DatabaseGroupEvaluator implements GroupEvaluator
{
    public function recompute(array $groupIds): array
    {
        $transitions = [];
        $level = array_values(array_unique($groupIds));

        for ($depth = 0; $depth < GroupHierarchy::MAX_DEPTH && $level !== []; $depth++) {
            sort($level);
            $parents = [];
            foreach ($this->evaluateLevel($level) as $parentId => $transition) {
                if ($parentId !== null) {
                    $parents[$parentId] = true;
                }
                if ($transition !== null) {
                    $transitions[] = $transition;
                }
            }
            $level = array_keys($parents);
        }

        return $transitions;
    }

    /**
     * @param  list<int>  $ids  Sorted group ids of one level.
     * @return iterable<int|null, Transition|null> Parent id keyed transitions; a null transition means nothing changed.
     */
    private function evaluateLevel(array $ids): iterable
    {
        // Lock order: group rows (shared) before their state rows (exclusive), each in id order, the same order
        // the API and the result recorder use, so concurrent writers queue instead of deadlocking. On SQLite
        // the lock clauses compile to nothing and the IMMEDIATE transaction serializes writers.
        $groups = DB::table('monitors')
            ->whereIn('id', $ids)
            ->where('type', MonitorType::Group->value)
            ->where('active', true)
            ->orderBy('id')
            ->sharedLock()
            ->get(['id', 'workspace_id', 'parent_id', 'interval_seconds', 'config']);

        $states = [];
        foreach (DB::table('monitor_states')->whereIn('monitor_id', $ids)->orderBy('monitor_id')->lockForUpdate()
            ->get(['monitor_id', 'status', 'status_since']) as $row) {
            $states[$this->int($row->monitor_id)] = $row;
        }

        $counts = $this->childCounts($ids);
        $lastResult = $this->lastResultAt($ids);
        $now = CarbonImmutable::now();
        $stamp = $now->format('Y-m-d H:i:s');
        $transitions = [];

        foreach ($groups as $group) {
            $id = $this->int($group->id);
            $state = $states[$id] ?? null;
            if ($state === null) {
                continue;
            }

            $config = json_decode($this->string($group->config), true);
            $config = is_array($config) ? $config : [];
            $count = $counts[$id] ?? [];
            [$status, $available, $total] = $this->evaluate(
                is_string($config['mode'] ?? null) ? $config['mode'] : 'all',
                is_numeric($config['quorum'] ?? null) ? (int) $config['quorum'] : 1,
                $count,
            );

            $from = MonitorStatus::tryFrom($this->string($state->status)) ?? MonitorStatus::Pending;
            $since = CarbonImmutable::parse($this->string($state->status_since));
            $changed = $status !== $from;
            $last = $lastResult[$id] ?? null;
            $due = $last === null || $last->addSeconds($this->int($group->interval_seconds))->lte($now);
            $parentId = $group->parent_id === null ? null : $this->int($group->parent_id);

            if (! $changed && ! $due) {
                yield $parentId => null;

                continue;
            }

            $message = "{$available}/{$total} children available";
            DB::table('check_results')->insert([
                'monitor_id' => $id,
                'status' => $status->value,
                'latency_ms' => null,
                'message' => $message,
                'facts' => null,
                'checked_at' => $stamp,
                'drift_ms' => null,
                'bucket_minute' => intdiv($now->getTimestamp(), 60) * 60,
            ]);
            DB::table('monitor_states')->where('monitor_id', $id)->update([
                'status' => $status->value,
                'status_since' => $changed ? $stamp : $since->format('Y-m-d H:i:s'),
                'last_checked_at' => $stamp,
                'consecutive_failures' => 0,
                'last_latency_ms' => null,
                'last_message' => $message,
                'updated_at' => $stamp,
            ]);

            if ($changed) {
                DB::table('monitor_events')->insert([
                    'workspace_id' => $this->int($group->workspace_id),
                    'monitor_id' => $id,
                    'kind' => MonitorEvent::KIND_STATUS_CHANGED,
                    'from_status' => $from->value,
                    'to_status' => $status->value,
                    'key' => null,
                    'old_value' => null,
                    'new_value' => null,
                    'message' => $message,
                    'created_at' => $stamp,
                ]);
            }

            yield $parentId => new Transition(
                monitorId: $id,
                workspaceId: $this->int($group->workspace_id),
                from: $from,
                effective: $status,
                consecutiveFailures: 0,
                changed: $changed,
                nextDelaySeconds: $this->int($group->interval_seconds),
                message: $message,
                previousSince: $since,
            );
        }
    }

    /**
     * @param  array<string, int>  $counts  Children per status.
     * @return array{0: MonitorStatus, 1: int, 2: int} Status, available children and counted children.
     */
    private function evaluate(string $mode, int $quorum, array $counts): array
    {
        $up = $counts[MonitorStatus::Up->value] ?? 0;
        $degraded = $counts[MonitorStatus::Degraded->value] ?? 0;
        $pending = $counts[MonitorStatus::Pending->value] ?? 0;
        $down = $counts[MonitorStatus::Down->value] ?? 0;
        $total = $up + $degraded + $pending + $down;
        $available = $up + $degraded + $pending;

        if ($total === 0) {
            return [MonitorStatus::Pending, 0, 0];
        }

        $status = match ($mode) {
            // A pending child may still turn out up, so the group is never reported down while one is pending.
            'any' => $up > 0 ? MonitorStatus::Up : ($degraded > 0 ? MonitorStatus::Degraded : ($pending > 0 ? MonitorStatus::Pending : MonitorStatus::Down)),
            'quorum' => $available >= $quorum ? MonitorStatus::Up : MonitorStatus::Down,
            default => $down > 0 ? MonitorStatus::Down : ($degraded > 0 || $pending > 0 ? MonitorStatus::Degraded : MonitorStatus::Up),
        };

        return [$status, $available, $total];
    }

    /**
     * Children that take part in a group: active ones whose state is neither paused nor in maintenance.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, int>>
     */
    private function childCounts(array $ids): array
    {
        $rows = DB::table('monitors')
            ->leftJoin('monitor_states', 'monitor_states.monitor_id', '=', 'monitors.id')
            ->whereIn('monitors.parent_id', $ids)
            ->where('monitors.active', true)
            ->groupBy('monitors.parent_id', 'monitor_states.status')
            ->get(['monitors.parent_id', 'monitor_states.status', DB::raw('count(*) as total')]);

        $counts = [];
        foreach ($rows as $row) {
            $status = $row->status === null ? MonitorStatus::Pending->value : $this->string($row->status);
            if (in_array($status, [MonitorStatus::Paused->value, MonitorStatus::Maintenance->value], true)) {
                continue;
            }
            $parent = $this->int($row->parent_id);
            $counts[$parent][$status] = ($counts[$parent][$status] ?? 0) + $this->int($row->total);
        }

        return $counts;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, CarbonImmutable>
     */
    private function lastResultAt(array $ids): array
    {
        $last = [];
        foreach (DB::table('check_results')->whereIn('monitor_id', $ids)->groupBy('monitor_id')
            ->get(['monitor_id', DB::raw('max(checked_at) as last_at')]) as $row) {
            $last[$this->int($row->monitor_id)] = CarbonImmutable::parse($this->string($row->last_at));
        }

        return $last;
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
