<?php

namespace App\Status\Maintenance;

use App\Alerting\Engine\AlertSignals;
use App\Enums\MonitorType;
use App\Monitoring\Engine\GroupEvaluator;
use App\Monitoring\Engine\MaintenanceOracle;
use App\Monitoring\Engine\PostCommit;
use App\Monitoring\Types\GroupHierarchy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Makes maintenance window boundaries take effect promptly.
 *
 * A monitor that enters or leaves a window changes status only when it is next evaluated. At a boundary the
 * scheduled monitors are asked to check right away and the groups are recomputed, both through the paths the
 * engine and the alerting contract already use; push and cron monitors are evaluated at their next ping or
 * deadline.
 */
final class MaintenanceSync
{
    private const CHUNK = 500;

    public function __construct(
        private readonly GroupEvaluator $groups,
        private readonly MaintenanceOracle $oracle,
    ) {}

    /**
     * Handles the monitors with a window start or end in `(from, to]`.
     *
     * @return array{requested: int, groups: int}
     */
    public function run(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $this->oracle->warm();
        $ids = $this->oracle instanceof CalendarMaintenanceOracle ? $this->oracle->monitorsWithBoundaryIn($from, $to) : [];
        if ($ids === []) {
            return ['requested' => 0, 'groups' => 0];
        }

        $result = DB::transaction(fn (): SyncResult => $this->apply($ids));
        $result->afterCommit();

        return ['requested' => $result->requested, 'groups' => $result->groups];
    }

    /**
     * Handles the monitors whose in-window state differs between two definitions of a maintenance. Runs inside
     * the transaction that wrote the new definition; the caller releases the result after commit and warms the
     * oracle. `$before` is null for a created maintenance, `$after` for a deleted one.
     */
    public function applyChange(?MaintenanceState $before, ?MaintenanceState $after): SyncResult
    {
        $now = CarbonImmutable::now();
        $beforeMuted = array_flip($this->mutedAt($before, $now));
        $afterMuted = array_flip($this->mutedAt($after, $now));
        $changed = [];
        foreach ($beforeMuted + $afterMuted as $id => $_) {
            if (isset($beforeMuted[$id]) !== isset($afterMuted[$id])) {
                $changed[] = $id;
            }
        }
        if ($changed === []) {
            return new SyncResult;
        }

        // The group recompute below asks the oracle, which must already know the written definition.
        if ($this->oracle instanceof CalendarMaintenanceOracle) {
            $this->oracle->refresh();
        }

        return $this->apply($changed);
    }

    /**
     * The monitors a definition mutes at the given moment, targets and all their descendants.
     *
     * @return list<int>
     */
    private function mutedAt(?MaintenanceState $state, CarbonImmutable $now): array
    {
        if ($state === null || $state->targetIds === []) {
            return [];
        }
        $inWindow = false;
        foreach ($state->schedule->windowsBetween($now, $now->addSecond()) as $window) {
            if ($window->contains($now)) {
                $inWindow = true;
                break;
            }
        }

        return $inWindow ? $this->withDescendants($state->targetIds) : [];
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function withDescendants(array $ids): array
    {
        $seen = array_fill_keys($ids, true);
        $frontier = $ids;
        for ($level = 0; $level < GroupHierarchy::MAX_DEPTH && $frontier !== []; $level++) {
            $next = [];
            foreach (array_chunk($frontier, self::CHUNK) as $chunk) {
                foreach (DB::table('monitors')->whereIn('parent_id', $chunk)->pluck('id') as $id) {
                    $id = is_numeric($id) ? (int) $id : 0;
                    if (! isset($seen[$id])) {
                        $seen[$id] = true;
                        $next[] = $id;
                    }
                }
            }
            $frontier = $next;
        }

        return array_keys($seen);
    }

    /**
     * Requests a check for the active scheduled monitors and recomputes the active groups.
     *
     * @param  list<int>  $ids
     */
    private function apply(array $ids): SyncResult
    {
        $now = CarbonImmutable::now();
        $leaves = [];
        $groups = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach (DB::table('monitors')->whereIn('id', $chunk)->where('active', true)->get(['id', 'type']) as $row) {
                $type = MonitorType::tryFrom(is_string($row->type) ? $row->type : '');
                $id = is_numeric($row->id) ? (int) $row->id : 0;
                if ($type === MonitorType::Group) {
                    $groups[] = $id;
                } elseif ($type !== null && $type->definition()->isScheduled()) {
                    $leaves[] = $id;
                }
            }
        }

        // A plain update: `updated_at` stays, so no monitor looks edited and no config reload is triggered.
        foreach (array_chunk($leaves, self::CHUNK) as $chunk) {
            DB::table('monitors')->whereIn('id', $chunk)->where('active', true)->update(['check_requested_at' => $now]);
        }

        $events = [];
        $jobs = [];
        if ($groups !== []) {
            $events = PostCommit::statusEvents($this->groups->recompute($groups), $now);
            $jobs = AlertSignals::queue($events);
        }

        return new SyncResult(count($leaves), count($groups), $events, $jobs);
    }
}
