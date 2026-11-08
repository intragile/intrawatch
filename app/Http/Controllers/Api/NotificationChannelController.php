<?php

namespace App\Http\Controllers\Api;

use App\Actions\Notifications\CreateNotificationChannel;
use App\Enums\ChannelType;
use App\Enums\TokenAbility;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPage;
use App\Http\Requests\NotificationChannelRequest;
use App\Http\Requests\UpdateNotificationChannelRequest;
use App\Http\Resources\NotificationChannelPresenter;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit;
use App\Support\ConfigMask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NotificationChannelController extends Controller
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $request->validate([
            'type' => ['sometimes', Rule::enum(ChannelType::class)],
            'q' => ['sometimes', 'string', 'max:120'],
        ]);

        $query = $workspace->notificationChannels()->withCount('monitors')->orderBy('notification_channels.id');
        $query->when($request->has('type'), fn (Builder $q) => $q->where('notification_channels.type', $request->string('type')->toString()));
        $query->when($request->filled('q'), function (Builder $q) use ($request): void {
            $needle = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower($request->string('q')->toString()));
            $q->whereRaw("lower(notification_channels.name) like ? escape '!'", ['%'.$needle.'%']);
        });

        $reveal = $this->revealsSensitive($request, $workspace);

        return CursorPage::from($query, $request, fn (Model $m): array => NotificationChannelPresenter::toArray($this->channel($m), $reveal));
    }

    public function store(NotificationChannelRequest $request, Workspace $workspace, CreateNotificationChannel $create): JsonResponse
    {
        $channel = $create->handle($workspace, $request->validatedChannel(), null);

        return response()->json(['data' => NotificationChannelPresenter::toArray($this->fresh($channel), $this->revealsSensitive($request, $workspace))], 201);
    }

    public function show(Request $request, Workspace $workspace, NotificationChannel $channel): JsonResponse
    {
        return response()->json(['data' => NotificationChannelPresenter::toArray($this->fresh($channel), $this->revealsSensitive($request, $workspace))]);
    }

    public function update(UpdateNotificationChannelRequest $request, Workspace $workspace, NotificationChannel $channel): JsonResponse
    {
        $changes = $request->changes();
        $secretsSent = $request->secretsSubmitted();

        DB::transaction(function () use ($request, $workspace, $channel, $changes, $secretsSent): void {
            $before = $channel->only(array_keys($changes));
            $oldSecretKeys = $this->secretKeys($channel->secrets);

            $channel->fill($changes)->save();

            $diff = [];
            foreach ($before as $field => $old) {
                if ($field === 'secrets') {
                    continue;
                }
                $new = $channel->getAttribute($field);
                if (self::canonical($old) === self::canonical($new)) {
                    continue;
                }
                // The change is decided on the raw values, then both sides are masked like the API response, so
                // a rotated webhook path is still recorded without ever landing in the append-only log.
                if ($field === 'config') {
                    $sensitive = $channel->type->definition()->sensitiveConfigKeys();
                    $old = is_array($old) ? ConfigMask::apply($old, $sensitive) : $old;
                    $new = is_array($new) ? ConfigMask::apply($new, $sensitive) : $new;
                }
                $diff[$field] = [$old, $new];
            }
            // A rotated secret keeps its key name, so the entry is recorded whenever secrets were sent.
            $newSecretKeys = $this->secretKeys($channel->secrets);
            if ($secretsSent || $oldSecretKeys !== $newSecretKeys) {
                $diff['secret_keys'] = [$oldSecretKeys, $newSecretKeys];
            }
            if ($request->applyToExisting() && ($attached = $this->attachToMonitors($workspace, $channel)) > 0) {
                $diff['applied_to_existing'] = [null, $attached];
            }
            if ($diff !== []) {
                Audit::record('notification_channel.updated', $channel, $diff);
            }
        });

        return response()->json(['data' => NotificationChannelPresenter::toArray($this->fresh($channel), $this->revealsSensitive($request, $workspace))]);
    }

    public function destroy(Workspace $workspace, NotificationChannel $channel): Response
    {
        DB::transaction(function () use ($channel): void {
            $channel->delete();
            Audit::record('notification_channel.deleted', $channel, ['name' => [$channel->name, null]]);
        });

        return response()->noContent();
    }

    /**
     * Whether the caller sees sensitive config values (a webhook path, an ntfy topic) in full: only those who may
     * manage channels, and for a token only one that can write them.
     */
    private function revealsSensitive(Request $request, Workspace $workspace): bool
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->can('manageNotifications', $workspace)) {
            return false;
        }

        return ! $user->authenticatedByToken() || $user->tokenCan(TokenAbility::NotificationsWrite->value);
    }

    private function attachToMonitors(Workspace $workspace, NotificationChannel $channel): int
    {
        return app(CreateNotificationChannel::class)->attachToMonitors($workspace, $channel);
    }

    /**
     * A JSON value with its object keys sorted, recursively. MySQL stores JSON objects with their keys in its own
     * order, so a value read back can differ from the submitted one in key order alone.
     */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function channel(Model $model): NotificationChannel
    {
        return $model instanceof NotificationChannel ? $model : throw new \LogicException('Expected a notification channel.');
    }

    private function fresh(NotificationChannel $channel): NotificationChannel
    {
        return NotificationChannel::query()->withCount('monitors')->findOrFail($channel->id);
    }

    /**
     * @param  array<array-key, mixed>|null  $secrets
     * @return list<string>
     */
    private function secretKeys(?array $secrets): array
    {
        $keys = array_map(strval(...), array_keys($secrets ?? []));
        sort($keys);

        return $keys;
    }
}
