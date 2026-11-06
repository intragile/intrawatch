<?php

namespace App\Monitoring\Engine;

use App\Enums\MonitorStatus;
use App\Events\ChecksRecorded;
use App\Events\MonitorChanged;
use App\Events\MonitorStatusChanged;
use App\Models\MonitorEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Persists check outcomes: results, monitor states, monitor events and group recomputes, all in one
 * transaction, then broadcasts what changed once that transaction has committed.
 */
final class ResultRecorder
{
    private const INSERT_CHUNK = 500;

    /**
     * Checks per ChecksRecorded event, which keeps each broadcast below the WebSocket message size limit.
     */
    public const BROADCAST_CHUNK = 200;

    public function __construct(
        private readonly StatusMachine $machine,
        private readonly MaintenanceOracle $maintenance,
        private readonly GroupEvaluator $groups,
    ) {}

    /**
     * Items of the same monitor are applied in the order given.
     *
     * @param  list<array{0: MonitorSpec, 1: CheckOutcome, 2: CarbonImmutable, 3: ?int}>  $items  Spec, outcome, checked-at, schedule drift in ms.
     * @param  bool  $broadcastChecks  Whether to dispatch ChecksRecorded; callers that throttle the broadcast pass false.
     */
    public function record(array $items, bool $broadcastChecks = true): RecordedBatch
    {
        if ($items === []) {
            return new RecordedBatch;
        }

        /** @var list<object> $events */
        $events = [];
        $batch = DB::transaction(function () use ($items, $broadcastChecks, &$events): RecordedBatch {
            return $this->persist($items, $broadcastChecks, $events);
        });

        // The results are committed at this point; see PostCommit for why a failing dispatch is swallowed.
        PostCommit::dispatch($events);

        return $batch;
    }

    /**
     * @param  list<array{0: MonitorSpec, 1: CheckOutcome, 2: CarbonImmutable, 3: ?int}>  $items
     * @param  list<object>  $events  Receives the events to dispatch after commit.
     */
    private function persist(array $items, bool $broadcastChecks, array &$events): RecordedBatch
    {
        $ids = array_values(array_unique(array_map(fn (array $item): int => $item[0]->id, $items)));
        sort($ids);

        // Lock order: monitor rows (shared) before state rows (exclusive), each in id order. The API takes
        // the same order when it pauses, edits or deletes a monitor (monitor row first, then its state row),
        // so the two paths queue behind each other instead of deadlocking. The shared lock also means a
        // pause or delete that is still in flight is waited for and then seen, so no result is recorded
        // after it commits. On SQLite both lock clauses compile to nothing and the IMMEDIATE transaction
        // serializes writers.
        $active = [];
        foreach (DB::table('monitors')->whereIn('id', $ids)->orderBy('id')->sharedLock()->get(['id', 'active']) as $row) {
            if ((bool) $row->active) {
                $active[$this->int($row->id)] = true;
            }
        }

        /** @var array<int, StateSnapshot> $states */
        $states = [];
        $stateRows = DB::table('monitor_states')
            ->whereIn('monitor_id', $ids)
            ->orderBy('monitor_id')
            ->lockForUpdate()
            ->get(['monitor_id', 'status', 'consecutive_failures', 'status_since', 'watch']);
        foreach ($stateRows as $row) {
            $states[$this->int($row->monitor_id)] = new StateSnapshot(
                MonitorStatus::tryFrom($this->string($row->status)) ?? MonitorStatus::Pending,
                $this->int($row->consecutive_failures),
                CarbonImmutable::parse($this->string($row->status_since)),
                StateSnapshot::normalizeWatch(json_decode($this->string($row->watch ?? ''), true)),
            );
        }

        $maintenance = $this->maintenanceByItem($items, $active);

        $now = CarbonImmutable::now();
        $results = [];
        $stateUpdates = [];
        $eventRows = [];
        $transitions = [];
        $checks = [];
        $parentIds = [];

        foreach ($items as $index => [$spec, $outcome, $checkedAt, $driftMs]) {
            if (! isset($active[$spec->id])) {
                continue;
            }

            $state = $states[$spec->id] ?? StateSnapshot::of(MonitorStatus::Pending, 0, $checkedAt);
            $transition = $this->machine->apply($spec, $state, $outcome, isset($maintenance[$index]));
            $since = $transition->changed ? $checkedAt : $state->since;

            $results[] = [
                'monitor_id' => $spec->id,
                'status' => $transition->effective->value,
                'latency_ms' => $outcome->latencyMs,
                'message' => $transition->message === '' ? null : $transition->message,
                'facts' => $outcome->facts === [] ? null : $this->json($outcome->facts),
                'checked_at' => $checkedAt,
                'drift_ms' => $driftMs,
                'bucket_minute' => intdiv($checkedAt->getTimestamp(), 60) * 60,
            ];
            $checks[$spec->workspaceId][] = [
                'monitor_id' => $spec->id,
                'status' => $transition->effective->value,
                'latency_ms' => $outcome->latencyMs,
                'checked_at' => $checkedAt->toIso8601ZuluString(),
            ];

            if ($transition->changed) {
                $eventRows[] = $this->eventRow($spec, MonitorEvent::KIND_STATUS_CHANGED, $transition->message, $checkedAt, [
                    'from_status' => $transition->from->value,
                    'to_status' => $transition->effective->value,
                ]);
                $events[] = new MonitorStatusChanged(
                    $spec->workspaceId, $spec->id, $transition->from, $transition->effective,
                    $checkedAt, $state->since, $transition->message,
                );
            }

            $watch = $state->watch;
            foreach ($outcome->watch as $key => $value) {
                $previous = $watch[$key] ?? null;
                if ($previous !== null && $previous !== $value) {
                    $eventRows[] = $this->eventRow($spec, MonitorEvent::KIND_CHANGED, mb_substr("{$key} changed", 0, 500), $checkedAt, [
                        'key' => $key,
                        'old_value' => $previous,
                        'new_value' => $value,
                    ]);
                    $events[] = new MonitorChanged($spec->workspaceId, $spec->id, $key, $previous, $value, $checkedAt);
                }
                $watch[$key] = $value;
            }

            $states[$spec->id] = new StateSnapshot($transition->effective, $transition->consecutiveFailures, $since, $watch);
            $stateUpdates[$spec->id] = [
                'monitor_id' => $spec->id,
                'status' => $transition->effective->value,
                'status_since' => $since,
                'last_checked_at' => $checkedAt,
                'consecutive_failures' => $transition->consecutiveFailures,
                'last_latency_ms' => $outcome->latencyMs,
                'last_message' => $transition->message === '' ? null : $transition->message,
                'watch' => $watch === [] ? null : $this->json($watch),
                'updated_at' => $now,
            ];
            $transitions[$spec->id] = $transition;

            if ($spec->parentId !== null) {
                $parentIds[$spec->parentId] = true;
            }
        }

        foreach (array_chunk($results, self::INSERT_CHUNK) as $chunk) {
            DB::table('check_results')->insert($chunk);
        }

        foreach (array_chunk(array_values($stateUpdates), self::INSERT_CHUNK) as $chunk) {
            DB::table('monitor_states')->upsert($chunk, ['monitor_id'], [
                'status', 'status_since', 'last_checked_at', 'consecutive_failures', 'last_latency_ms',
                'last_message', 'watch', 'updated_at',
            ]);
        }

        foreach (array_chunk($eventRows, self::INSERT_CHUNK) as $chunk) {
            DB::table('monitor_events')->insert($chunk);
        }

        $groupTransitions = $parentIds === [] ? [] : $this->groups->recompute(array_keys($parentIds));
        array_push($events, ...PostCommit::statusEvents($groupTransitions, $now));

        if ($broadcastChecks) {
            foreach ($checks as $workspaceId => $list) {
                foreach (array_chunk($list, self::BROADCAST_CHUNK) as $chunk) {
                    $events[] = new ChecksRecorded($workspaceId, $chunk);
                }
            }
        }

        return new RecordedBatch($transitions, $groupTransitions);
    }

    /**
     * Which items fall into a maintenance window at their own check time. The oracle is asked once per
     * distinct check second, which keeps a flushed batch to a handful of calls.
     *
     * @param  list<array{0: MonitorSpec, 1: CheckOutcome, 2: CarbonImmutable, 3: ?int}>  $items
     * @param  array<int, true>  $active
     * @return array<int, true> Item indexes under maintenance.
     */
    private function maintenanceByItem(array $items, array $active): array
    {
        /** @var array<int, array{at: CarbonImmutable, ids: array<int, true>, items: list<int>}> $bySecond */
        $bySecond = [];
        foreach ($items as $index => [$spec, , $checkedAt]) {
            if (! isset($active[$spec->id])) {
                continue;
            }
            $second = $checkedAt->getTimestamp();
            $bySecond[$second] ??= ['at' => $checkedAt, 'ids' => [], 'items' => []];
            $bySecond[$second]['ids'][$spec->id] = true;
            $bySecond[$second]['items'][] = $index;
        }

        $maintenance = [];
        foreach ($bySecond as $group) {
            $inWindow = array_fill_keys($this->maintenance->underMaintenance(array_keys($group['ids']), $group['at']), true);
            if ($inWindow === []) {
                continue;
            }
            foreach ($group['items'] as $index) {
                if (isset($inWindow[$items[$index][0]->id])) {
                    $maintenance[$index] = true;
                }
            }
        }

        return $maintenance;
    }

    /**
     * @param  array<string, string|null>  $columns
     * @return array<string, mixed>
     */
    private function eventRow(MonitorSpec $spec, string $kind, string $message, CarbonImmutable $at, array $columns): array
    {
        return array_merge([
            'workspace_id' => $spec->workspaceId,
            'monitor_id' => $spec->id,
            'kind' => $kind,
            'from_status' => null,
            'to_status' => null,
            'key' => null,
            'old_value' => null,
            'new_value' => null,
            'message' => $message,
            'created_at' => $at,
        ], $columns);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException) {
            return '{}';
        }
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
