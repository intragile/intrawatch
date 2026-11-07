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
            $data['updates'] = self::updates($incident);
        }

        return $data;
    }

    /**
     * The timeline, newest first.
     *
     * @return list<array{id: int, status: string, message: string, created_at: string, updated_at: string|null}>
     */
    private static function updates(Incident $incident): array
    {
        // A loop rather than Collection::map, so the generated API schema can see the entry shape.
        $updates = [];
        foreach ($incident->updates->sortByDesc(static fn ($update): array => [$update->created_at->getTimestamp(), $update->id]) as $update) {
            $updates[] = IncidentUpdateResource::toArray($update);
        }

        return $updates;
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return list<int>
     */
    private static function ids(array $ids): array
    {
        // A loop rather than array_map, so the generated API schema can tell the items are integers.
        $list = [];
        foreach ($ids as $id) {
            $list[] = is_numeric($id) ? (int) $id : 0;
        }
        sort($list);

        return $list;
    }
}
