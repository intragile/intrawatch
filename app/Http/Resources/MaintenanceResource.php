<?php

namespace App\Http\Resources;

use App\Models\Maintenance;
use App\Status\Maintenance\MaintenanceSchedule;
use App\Status\Maintenance\Window;
use Carbon\CarbonImmutable;

/**
 * The public JSON shape of a maintenance, with its targets and the state computed from its schedule.
 */
final class MaintenanceResource
{
    /**
     * The window in progress, or else the next one; null when there is none.
     */
    public static function window(Maintenance $maintenance, string $workspaceTimezone, CarbonImmutable $now): ?Window
    {
        return MaintenanceSchedule::fromModel($maintenance, $workspaceTimezone)->nextWindow($now);
    }

    /**
     * @param  list<int>|null  $monitorIds  Taken from the loaded relation when null.
     * @param  list<int>|null  $statusPageIds
     * @return array<string, mixed>
     */
    public static function toArray(Maintenance $maintenance, string $workspaceTimezone, CarbonImmutable $now, ?array $monitorIds = null, ?array $statusPageIds = null): array
    {
        $window = self::window($maintenance, $workspaceTimezone, $now);
        $monitorIds ??= self::ids($maintenance->monitors->pluck('id')->all());
        $statusPageIds ??= self::ids($maintenance->statusPages->pluck('id')->all());

        return [
            'id' => $maintenance->id,
            'title' => $maintenance->title,
            'description' => $maintenance->description,
            'strategy' => $maintenance->strategy->value,
            'timezone' => $maintenance->timezone,
            'starts_at' => $maintenance->starts_at?->toIso8601String(),
            'ends_at' => $maintenance->ends_at?->toIso8601String(),
            'start_time' => $maintenance->start_time,
            'duration_minutes' => $maintenance->duration_minutes,
            'every_days' => $maintenance->every_days,
            'weekdays' => $maintenance->weekdays,
            'month_days' => $maintenance->month_days,
            'cron' => $maintenance->cron,
            'valid_from' => $maintenance->valid_from,
            'valid_until' => $maintenance->valid_until,
            'active' => $maintenance->active,
            'monitor_ids' => $monitorIds,
            'status_page_ids' => $statusPageIds,
            'in_progress' => $window !== null && $window->contains($now),
            'next_window' => $window === null ? null : [
                'starts_at' => $window->start->toIso8601String(),
                'ends_at' => $window->end?->toIso8601String(),
            ],
            'created_at' => $maintenance->created_at?->toIso8601String(),
            'updated_at' => $maintenance->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return list<int>
     */
    private static function ids(array $ids): array
    {
        $ids = array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids));
        sort($ids);

        return $ids;
    }
}
