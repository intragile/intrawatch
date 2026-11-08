<?php

namespace App\Http\Resources;

use App\Models\NotificationChannel;
use App\Support\JsonObject;
use App\Support\SecretKeys;

/**
 * The public JSON shape of a notification channel. Secrets are exposed by key name only, and the config keys a
 * type marks as sensitive are masked unless the caller may manage channels.
 */
final class NotificationChannelPresenter
{
    public const MASK = '***';

    /**
     * @param  bool  $revealSensitive  Whether the caller sees sensitive config values in full. Masked unless stated.
     * @return array<string, mixed>
     */
    public static function toArray(NotificationChannel $channel, bool $revealSensitive = false): array
    {
        $config = $revealSensitive
            ? $channel->config
            : self::masked($channel->config, $channel->type->definition()->sensitiveConfigKeys());
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

    /**
     * Replaces sensitive values: an http(s) URL keeps its scheme, host and port, anything else becomes the mask.
     * Keys may be dotted paths; a null value stays null.
     *
     * @param  array<array-key, mixed>  $config
     * @param  list<string>  $keys
     * @return array<array-key, mixed>
     */
    private static function masked(array $config, array $keys): array
    {
        foreach ($keys as $key) {
            $config = self::maskPath($config, explode('.', $key));
        }

        return $config;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $path
     * @return array<array-key, mixed>
     */
    private static function maskPath(array $values, array $path): array
    {
        $head = array_shift($path);
        if ($head === null || ! isset($values[$head])) {
            return $values;
        }
        if ($path === []) {
            $values[$head] = self::mask($values[$head]);
        } elseif (is_array($values[$head])) {
            $values[$head] = self::maskPath($values[$head], $path);
        }

        return $values;
    }

    private static function mask(mixed $value): string
    {
        if (is_string($value)) {
            $parts = parse_url($value);
            if (is_array($parts) && isset($parts['scheme'], $parts['host']) && in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
                $port = isset($parts['port']) ? ':'.$parts['port'] : '';

                return strtolower($parts['scheme']).'://'.$parts['host'].$port.'/'.self::MASK;
            }
        }

        return self::MASK;
    }
}
