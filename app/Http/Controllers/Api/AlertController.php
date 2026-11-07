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
     * `since` is an ISO 8601 date or date-time with an offset (`2026-03-10`, `2026-03-10T10:00:00Z`).
     *
     * @return array<string, list<mixed>>
     */
    private static function filterRules(): array
    {
        return [
            'kind' => ['sometimes', Rule::enum(AlertKind::class)],
            'since' => ['sometimes', 'string', 'regex:/^\\d{4}-\\d{2}-\\d{2}(T\\d{2}:\\d{2}:\\d{2}(\\.\\d{1,6})?(Z|[+-]\\d{2}:\\d{2}))?$/', 'date'],
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
     * @param  Builder<AlertEvent>  $query
     */
    private function page(Builder $query, Request $request, Workspace $workspace): JsonResponse
    {
        $response = CursorPage::from($query, $request, fn (Model $model): array => $this->alert($model));

        /** @var array{data: list<array<string, mixed>>, meta: array<string, mixed>} $payload */
        $payload = $response->getData(true);
        $eventIds = array_map(static fn (array $alert): mixed => $alert['id'], $payload['data']);
        $deliveries = $this->deliveries($workspace, array_values(array_filter($eventIds, 'is_int')));

        foreach ($payload['data'] as $i => $alert) {
            $payload['data'][$i]['deliveries'] = is_int($alert['id']) ? ($deliveries[$alert['id']] ?? []) : [];
        }

        return $response->setData($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function alert(Model $model): array
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
            'deliveries' => [],
        ];
    }

    /**
     * The deliveries naming any of the given alerts, keyed by alert id, oldest first. A digest delivery covers
     * several alerts and is listed under each of them. One query for the whole page.
     *
     * @param  list<int>  $eventIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function deliveries(Workspace $workspace, array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $rows = AlertDelivery::query()
            ->with('channel:id,name,type')
            ->where('workspace_id', $workspace->id)
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
