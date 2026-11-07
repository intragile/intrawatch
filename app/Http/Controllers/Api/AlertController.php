<?php

namespace App\Http\Controllers\Api;

use App\Enums\AlertKind;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPage;
use App\Models\AlertDelivery;
use App\Models\AlertEvent;
use App\Models\Monitor;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Read-only alert history: every alert raised in the workspace and what happened to its deliveries. Alerts
 * outlive their monitor (then `monitor` is null) and deliveries outlive their channel (then `channel` is
 * null). History is kept for `RETENTION_ALERT_DAYS`.
 */
class AlertController extends Controller
{
    /**
     * Allowed lead of a delivery's `created_at` over its alert's. The alert carries the time of the check result,
     * taken on the checker's host, and the delivery the evaluation time on the queue worker's host; with several
     * hosts their clocks may differ slightly.
     */
    private const DELIVERY_CLOCK_SKEW_SECONDS = 300;

    /**
     * List the workspace's alerts, newest first. Filters: `monitor_id`, `kind`, `since` (ISO 8601, inclusive).
     */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $request->validate([
            'monitor_id' => ['sometimes', 'integer', 'min:1'],
            ...self::filterRules(),
        ]);

        $query = $this->query($request, $workspace);
        $query->when($request->has('monitor_id'), fn (Builder $q) => $q->where('monitor_id', $request->integer('monitor_id')));

        return $this->page($query, $request, $workspace);
    }

    /**
     * List one monitor's alerts, newest first. Filters: `kind`, `since` (ISO 8601, inclusive).
     */
    public function monitor(Request $request, Workspace $workspace, Monitor $monitor): JsonResponse
    {
        $request->validate(self::filterRules());

        $query = $this->query($request, $workspace)->where('monitor_id', $monitor->id);

        return $this->page($query, $request, $workspace);
    }

    /**
     * `since` is an ISO 8601 date or a date-time to the second with an offset (`2026-03-10`, `2026-03-10T10:00:00Z`,
     * `2026-03-10T11:00:00+01:00`); fractional seconds are rejected.
     *
     * @return array<string, list<mixed>>
     */
    private static function filterRules(): array
    {
        return [
            'kind' => ['sometimes', Rule::enum(AlertKind::class)],
            'since' => ['sometimes', 'string', 'regex:/^\\d{4}-\\d{2}-\\d{2}(T\\d{2}:\\d{2}:\\d{2}(Z|[+-]\\d{2}:\\d{2}))?$/', 'date'],
        ];
    }

    /**
     * @return Builder<AlertEvent>
     */
    private function query(Request $request, Workspace $workspace): Builder
    {
        $query = AlertEvent::query()
            ->with('monitor:id,name')
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('id');

        $query->when($request->has('kind'), fn (Builder $q) => $q->where('kind', $request->string('kind')->toString()));
        $query->when($request->has('since'), fn (Builder $q) => $q->where(
            'created_at',
            '>=',
            CarbonImmutable::parse($request->string('since')->toString())->utc()->format('Y-m-d H:i:s'),
        ));

        return $query;
    }

    /**
     * Pages the alerts. The deliveries of the whole page are loaded right after the page query, in one query, and
     * each item reads its own from that map.
     *
     * @param  Builder<AlertEvent>  $query
     */
    private function page(Builder $query, Request $request, Workspace $workspace): JsonResponse
    {
        /** @var array<int, list<array<string, mixed>>> $deliveries */
        $deliveries = [];
        $query->afterQuery(function (Collection $events) use ($workspace, &$deliveries): void {
            $deliveries = $this->deliveries($workspace, $events);
        });

        return CursorPage::from($query, $request, function (Model $model) use (&$deliveries): array {
            return $this->alert($model, $deliveries);
        });
    }

    /**
     * @param  array<int, list<array<string, mixed>>>  $deliveries  Keyed by alert id.
     * @return array<string, mixed>
     */
    private function alert(Model $model, array $deliveries): array
    {
        /** @var AlertEvent $event */
        $event = $model;
        $monitor = $event->monitor;

        return [
            'id' => $event->id,
            'kind' => $event->kind->value,
            'monitor' => $monitor === null ? null : ['id' => $monitor->id, 'name' => $monitor->name],
            'status' => $event->status?->value,
            'previous_status' => $event->previous_status?->value,
            'message' => $event->message,
            'duration_seconds' => $event->duration_seconds,
            // Rebuilt in a fixed key order: MySQL hands JSON objects back with its own key order.
            'changes' => $event->changes === null ? null : array_map(
                static fn (array $change): array => ['key' => $change['key'], 'old' => $change['old'], 'new' => $change['new']],
                $event->changes,
            ),
            'suppressed_by_monitor_id' => $event->suppressed_by_monitor_id,
            'created_at' => $event->created_at->toIso8601String(),
            'deliveries' => $deliveries[$event->id] ?? [],
        ];
    }

    /**
     * The deliveries naming any of the given alerts, keyed by alert id, oldest first. A digest delivery covers
     * several alerts and is listed under each of them. One query for the whole page.
     *
     * A delivery is written when its alert is evaluated, which is at or after the alert's `created_at` (the time
     * of the check result that raised it), so the lookup starts at the oldest alert of the page, less
     * DELIVERY_CLOCK_SKEW_SECONDS, instead of scanning the workspace's whole delivery history.
     *
     * @param  Collection<int, AlertEvent>  $events
     * @return array<int, list<array<string, mixed>>>
     */
    private function deliveries(Workspace $workspace, Collection $events): array
    {
        if ($events->isEmpty()) {
            return [];
        }
        $eventIds = $events->map(static fn (AlertEvent $event): int => $event->id)->values()->all();
        $oldest = $events->min(static fn (AlertEvent $event): int => $event->created_at->getTimestamp());
        $since = CarbonImmutable::createFromTimestamp(is_int($oldest) ? $oldest : 0, 'UTC')
            ->subSeconds(self::DELIVERY_CLOCK_SKEW_SECONDS);

        $rows = AlertDelivery::query()
            ->with('channel:id,name,type')
            ->where('workspace_id', $workspace->id)
            ->where('created_at', '>=', $since->format('Y-m-d H:i:s'))
            ->where(function (Builder $q) use ($eventIds): void {
                foreach ($eventIds as $id) {
                    $q->orWhereJsonContains('alert_event_ids', $id);
                }
            })
            ->orderBy('id')
            ->get();

        $wanted = array_fill_keys($eventIds, true);
        $byEvent = [];
        foreach ($rows as $delivery) {
            $channel = $delivery->channel;
            $item = [
                'id' => $delivery->id,
                'channel' => $channel === null ? null : ['id' => $channel->id, 'name' => $channel->name, 'type' => $channel->type->value],
                'status' => $delivery->status->value,
                'attempts' => $delivery->attempts,
                'last_error' => $delivery->last_error,
                'sent_at' => $delivery->sent_at?->toIso8601String(),
            ];
            foreach ($delivery->alert_event_ids as $id) {
                if (isset($wanted[$id])) {
                    $byEvent[$id][] = $item;
                }
            }
        }

        return $byEvent;
    }
}
