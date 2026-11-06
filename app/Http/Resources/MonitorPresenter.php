<?php

namespace App\Http\Resources;

use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The public JSON shape of a monitor. Secrets are exposed by key name only.
 */
final class MonitorPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Monitor $monitor): array
    {
        $state = $monitor->state;
        $secretKeys = array_map(strval(...), array_keys($monitor->secrets ?? []));
        sort($secretKeys);

        return [
            'id' => $monitor->id,
            'name' => $monitor->name,
            'description' => $monitor->description,
            'type' => $monitor->type->value,
            'active' => $monitor->active,
            'interval_seconds' => $monitor->interval_seconds,
            'timeout_seconds' => $monitor->timeout_seconds,
            'retries' => $monitor->retries,
            'retry_interval_seconds' => $monitor->retry_interval_seconds,
            'degraded_threshold_ms' => $monitor->degraded_threshold_ms,
            'inverted' => $monitor->inverted,
            'parent_id' => $monitor->parent_id,
            'config' => $monitor->config,
            'secret_keys' => $secretKeys,
            'tags' => $monitor->tags->map(static fn (Tag $tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
                'color' => $tag->color,
                'value' => self::pivotValue($tag),
            ])->values()->all(),
            'push_url' => self::pushUrl($monitor),
            'state' => [
                'status' => $state->status->value,
                'since' => $state->status_since->toIso8601String(),
                'last_checked_at' => $state->last_checked_at?->toIso8601String(),
                'last_latency_ms' => $state->last_latency_ms,
                'last_message' => $state->last_message,
            ],
            'created_at' => $monitor->created_at?->toIso8601String(),
            'updated_at' => $monitor->updated_at?->toIso8601String(),
        ];
    }

    private static function pivotValue(Tag $tag): ?string
    {
        $pivot = $tag->getRelation('pivot');
        $value = $pivot instanceof Pivot ? $pivot->getAttribute('value') : null;

        return is_string($value) ? $value : null;
    }

    public static function pushUrl(Monitor $monitor): ?string
    {
        if ($monitor->push_token === null || ! in_array($monitor->type, [MonitorType::Push, MonitorType::Cron], true)) {
            return null;
        }

        return rtrim(config()->string('app.url'), '/').'/api/v1/push/'.$monitor->push_token;
    }
}
