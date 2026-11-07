<?php

namespace App\Http\Controllers\Api;

use App\Enums\MonitorStatus;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPage;
use App\Models\CheckResult;
use App\Models\CheckRollup;
use App\Models\Monitor;
use App\Models\MonitorEvent;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Read-only history of a monitor: raw results, rollups and events. Time windows are half-open: `from` is
 * inclusive, `to` is exclusive.
 */
class MonitorHistoryController extends Controller
{
    /**
     * List the monitor's raw check results, newest first. Raw results are kept for `RETENTION_RAW_HOURS`.
     * Filters: `from`, `to` (ISO 8601), `status`.
     *
     * @response array{data: list<array{id: int, status: \App\Enums\MonitorStatus, latency_ms: int|null, message: string|null, facts: array<string, mixed>|null, checked_at: string}>, meta: array{next_cursor: string|null, limit: int}}
     */
    public function results(Request $request, Workspace $workspace, Monitor $monitor): JsonResponse
    {
        $request->validate([
            ...self::windowRules($request),
            'status' => ['sometimes', Rule::enum(MonitorStatus::class)],
        ]);

        $query = CheckResult::query()
            ->where('monitor_id', $monitor->id)
            ->orderByDesc('checked_at')
            ->orderByDesc('id');

        if ($request->has('from')) {
            $query->where('checked_at', '>=', $this->dateTime($request->string('from')->toString()));
        }
        if ($request->has('to')) {
            $query->where('checked_at', '<', $this->dateTime($request->string('to')->toString()));
        }
        if ($request->has('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return CursorPage::from($query, $request, function (Model $model): array {
            /** @var CheckResult $result */
            $result = $model;

            return [
                'id' => $result->id,
                'status' => $result->status->value,
                'latency_ms' => $result->latency_ms,
                'message' => $result->message,
                'facts' => $result->facts,
                'checked_at' => $result->checked_at->toIso8601String(),
            ];
        });
    }

    /**
     * List the monitor's rollups of one bucket size, oldest first. Day buckets start at midnight in the
     * workspace timezone. `availability` is (up + degraded + pending) / (total - maintenance), or null when
     * every check fell into maintenance. Filters: `bucket` (required), `from`, `to` (ISO 8601, applied to the
     * bucket start).
     *
     * @response array{data: list<array{bucket_start: string, total: int, up: int, down: int, degraded: int, pending: int, maintenance: int, availability: float|null, latency_min: int|null, latency_max: int|null, latency_avg: int|null}>, meta: array{next_cursor: string|null, limit: int}}
     */
    public function rollups(Request $request, Workspace $workspace, Monitor $monitor): JsonResponse
    {
        $request->validate([
            'bucket' => ['required', Rule::in(CheckRollup::BUCKETS)],
            ...self::windowRules($request),
        ]);

        $query = CheckRollup::query()
            ->where('monitor_id', $monitor->id)
            ->where('bucket', $request->string('bucket')->toString())
            ->orderBy('bucket_start');

        if ($request->has('from')) {
            $query->where('bucket_start', '>=', CarbonImmutable::parse($request->string('from')->toString())->getTimestamp());
        }
        if ($request->has('to')) {
            $query->where('bucket_start', '<', CarbonImmutable::parse($request->string('to')->toString())->getTimestamp());
        }

        return CursorPage::from($query, $request, function (Model $model): array {
            /** @var CheckRollup $rollup */
            $rollup = $model;

            return [
                'bucket_start' => CarbonImmutable::createFromTimestamp($rollup->bucket_start, 'UTC')->toIso8601String(),
                'total' => $rollup->total,
                'up' => $rollup->up,
                'down' => $rollup->down,
                'degraded' => $rollup->degraded,
                'pending' => $rollup->pending,
                'maintenance' => $rollup->maintenance,
                'availability' => $rollup->availability(),
                'latency_min' => $rollup->latency_min,
                'latency_max' => $rollup->latency_max,
                'latency_avg' => $rollup->latency_avg,
            ];
        });
    }

    /**
     * List the monitor's events (status changes and changes of watched values), newest first. Filter: `kind`.
     *
     * @response array{data: list<array{id: int, kind: string, from_status: \App\Enums\MonitorStatus|null, to_status: \App\Enums\MonitorStatus|null, key: string|null, old_value: string|null, new_value: string|null, message: string, created_at: string}>, meta: array{next_cursor: string|null, limit: int}}
     */
    public function events(Request $request, Workspace $workspace, Monitor $monitor): JsonResponse
    {
        $request->validate([
            'kind' => ['sometimes', Rule::in([MonitorEvent::KIND_STATUS_CHANGED, MonitorEvent::KIND_CHANGED])],
        ]);

        $query = MonitorEvent::query()->where('monitor_id', $monitor->id)->orderByDesc('id');
        $query->when($request->has('kind'), fn (Builder $q) => $q->where('kind', $request->string('kind')->toString()));

        return CursorPage::from($query, $request, function (Model $model): array {
            /** @var MonitorEvent $event */
            $event = $model;

            return [
                'id' => $event->id,
                'kind' => $event->kind,
                'from_status' => $event->from_status?->value,
                'to_status' => $event->to_status?->value,
                'key' => $event->key,
                'old_value' => $event->old_value,
                'new_value' => $event->new_value,
                'message' => $event->message,
                'created_at' => $event->created_at->toIso8601String(),
            ];
        });
    }

    /**
     * An ISO 8601 date or date-time with an offset (`2026-03-10`, `2026-03-10T10:00:00Z`,
     * `2026-03-10T10:00:00.123+01:00`); `to` must be after `from`.
     *
     * @return array<string, list<string>>
     */
    private static function windowRules(Request $request): array
    {
        $iso = 'regex:/^\\d{4}-\\d{2}-\\d{2}(T\\d{2}:\\d{2}:\\d{2}(\\.\\d{1,6})?(Z|[+-]\\d{2}:\\d{2}))?$/';

        return [
            'from' => ['sometimes', 'string', $iso, 'date'],
            'to' => ['sometimes', 'string', $iso, 'date', ...($request->has('from') ? ['after:from'] : [])],
        ];
    }

    private function dateTime(string $value): string
    {
        return CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s');
    }
}
