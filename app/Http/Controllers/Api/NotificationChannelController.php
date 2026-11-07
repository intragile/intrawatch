<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChannelType;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPage;
use App\Http\Requests\NotificationChannelRequest;
use App\Http\Resources\NotificationChannelPresenter;
use App\Models\NotificationChannel;
use App\Models\Workspace;
use App\Support\Audit;
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
    private const ATTACH_CHUNK = 500;

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

        return CursorPage::from($query, $request, fn (Model $m): array => NotificationChannelPresenter::toArray($this->channel($m)));
    }

    public function store(NotificationChannelRequest $request, Workspace $workspace): JsonResponse
    {
        $channel = DB::transaction(function () use ($request, $workspace): NotificationChannel {
            $channel = $workspace->notificationChannels()->create($request->channelAttributes());

            $changes = [
                'name' => [null, $channel->name],
                'type' => [null, $channel->type->value],
                'config' => [null, $channel->config],
                'secret_keys' => [[], $this->secretKeys($channel->secrets)],
                'templates' => [null, $channel->templates],
                'is_default' => [null, $channel->is_default],
                'active' => [null, $channel->active],
            ];
            if ($request->applyToExisting() && ($attached = $this->attachToMonitors($workspace, $channel)) > 0) {
                $changes['applied_to_existing'] = [null, $attached];
            }
            Audit::record('notification_channel.created', $channel, $changes);

            return $channel;
        });

        return response()->json(['data' => NotificationChannelPresenter::toArray($this->fresh($channel))], 201);
    }

    public function show(Workspace $workspace, NotificationChannel $channel): JsonResponse
    {
        return response()->json(['data' => NotificationChannelPresenter::toArray($this->fresh($channel))]);
    }

    public function update(NotificationChannelRequest $request, Workspace $workspace, NotificationChannel $channel): JsonResponse
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
                if (self::canonical($old) !== self::canonical($new)) {
                    $diff[$field] = [$old, $new];
                }
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

        return response()->json(['data' => NotificationChannelPresenter::toArray($this->fresh($channel))]);
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
     * Attaches the channel to every monitor of the workspace that does not have it yet.
     *
     * @return int The number of monitors newly attached.
     */
    private function attachToMonitors(Workspace $workspace, NotificationChannel $channel): int
    {
        $attached = 0;
        $workspace->monitors()->select('monitors.id')->chunkById(self::ATTACH_CHUNK, function ($monitors) use ($channel, &$attached): void {
            $rows = [];
            foreach ($monitors as $monitor) {
                $rows[] = ['monitor_id' => $monitor->id, 'notification_channel_id' => $channel->id];
            }
            $attached += DB::table('monitor_notification')->insertOrIgnore($rows);
        }, 'monitors.id', 'id');

        return $attached;
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
