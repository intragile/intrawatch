<?php

namespace App\Http\Resources;

use App\Models\Incident;

/**
 * The JSON shape of an incident with its targets; the timeline is included when the updates are loaded.
 */
final class IncidentResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Incident $incident): array
    {
        $data = [
            'id' => $incident->id,
            'title' => $incident->title,
            'impact' => $incident->impact->value,
            'status' => $incident->status->value,
            'started_at' => $incident->started_at->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
            'monitor_ids' => self::ids($incident->monitors->pluck('id')->all()),
            'status_page_ids' => self::ids($incident->statusPages->pluck('id')->all()),
            'created_at' => $incident->created_at?->toIso8601String(),
            'updated_at' => $incident->updated_at?->toIso8601String(),
        ];

        if ($incident->relationLoaded('updates')) {
            $data['updates'] = $incident->updates
                ->sortByDesc(static fn ($update): array => [$update->created_at->getTimestamp(), $update->id])
                ->map(static fn ($update): array => IncidentUpdateResource::toArray($update))
                ->values()
                ->all();
        }

        return $data;
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
