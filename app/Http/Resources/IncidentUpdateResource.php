<?php

namespace App\Http\Resources;

use App\Models\IncidentUpdate;

/**
 * The JSON shape of one incident timeline entry.
 */
final class IncidentUpdateResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(IncidentUpdate $update): array
    {
        return [
            'id' => $update->id,
            'status' => $update->status->value,
            'message' => $update->message,
            'created_at' => $update->created_at->toIso8601String(),
            'updated_at' => $update->updated_at?->toIso8601String(),
        ];
    }
}
