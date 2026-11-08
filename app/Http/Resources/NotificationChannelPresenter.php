<?php

namespace App\Http\Resources;

use App\Models\NotificationChannel;
use App\Support\ConfigMask;
use App\Support\JsonObject;
use App\Support\SecretKeys;

/**
 * The public JSON shape of a notification channel. Secrets are exposed by key name only, and the config keys a
 * type marks as sensitive are masked unless the caller may manage channels.
 */
final class NotificationChannelPresenter
{
    /** Kept for existing callers; the mask itself lives in ConfigMask. */
    public const MASK = ConfigMask::MASK;

    /**
     * @param  bool  $revealSensitive  Whether the caller sees sensitive config values in full. Masked unless stated.
     * @return array<string, mixed>
     */
    public static function toArray(NotificationChannel $channel, bool $revealSensitive = false): array
    {
        $config = $revealSensitive
            ? $channel->config
            : ConfigMask::apply($channel->config, $channel->type->definition()->sensitiveConfigKeys());
        $secretKeys = SecretKeys::of($channel->secrets);
        $count = $channel->getAttribute('monitors_count');

        return [
            'id' => $channel->id,
            'name' => $channel->name,
            'type' => $channel->type->value,
            'config' => JsonObject::of($config),
            'secret_keys' => $secretKeys,
            'templates' => JsonObject::of($channel->templates),
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
