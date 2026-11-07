<?php

namespace App\Http\Resources;

use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The public JSON shape of a monitor. Secrets are exposed by key name only.
 *
 * The push URL carries the push token, the only credential of the push endpoints: whoever has it can report a
 * monitor up, down or running. It is therefore shown only to callers who may manage monitors; everyone else
 * sees null and has_push_token.
 */
final class MonitorPresenter
{
    /**
     * Whether $user, with the token of the current request if any, may see push URLs in $workspace: members
     * with the Editor role or higher, and through a token only when it also holds monitors:write.
     */
    public static function revealsPushUrl(?User $user, Workspace $workspace): bool
    {
        return $user !== null && $user->can('manageMonitors', $workspace)
            && (! $user->authenticatedByToken() || $user->tokenCan('monitors:write'));
    }

    /**
     * @param  bool  $revealPushUrl  Whether the caller may see the push URL (see revealsPushUrl()).
     * @return array<string, mixed>
     */
    public static function toArray(Monitor $monitor, bool $revealPushUrl): array
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
            'push_url' => $revealPushUrl ? self::pushUrl($monitor) : null,
            'has_push_token' => self::pushUrl($monitor) !== null,
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
