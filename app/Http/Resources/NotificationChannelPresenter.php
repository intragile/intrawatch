<?php

namespace App\Http\Resources;

use App\Models\NotificationChannel;

/**
 * The public JSON shape of a notification channel. Secrets are exposed by key name only.
 */
final class NotificationChannelPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(NotificationChannel $channel): array
    {
        $secretKeys = array_map(strval(...), array_keys($channel->secrets ?? []));
        sort($secretKeys);
        $count = $channel->getAttribute('monitors_count');

        return [
            'id' => $channel->id,
            'name' => $channel->name,
            'type' => $channel->type->value,
            'config' => (object) $channel->config,
            'secret_keys' => $secretKeys,
            'templates' => $channel->templates === null ? null : (object) $channel->templates,
            'is_default' => $channel->is_default,
            'active' => $channel->active,
            'monitor_count' => is_numeric($count) ? (int) $count : $channel->monitors()->count(),
            'last_success_at' => $channel->last_success_at?->toIso8601String(),
            'last_failure_at' => $channel->last_failure_at?->toIso8601String(),
            'last_error' => $channel->last_error,
            'created_at' => $channel->created_at?->toIso8601String(),
            'updated_at' => $channel->updated_at?->toIso8601String(),
        ];
    }
}
