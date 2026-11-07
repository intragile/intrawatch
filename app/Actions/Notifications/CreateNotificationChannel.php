<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Http\Requests\NotificationChannelRequest;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a notification channel. Validation is the one of `POST /notification-channels`: the same form
 * request evaluates the input, so the API, the import and any other caller accept and refuse the same values.
 */
class CreateNotificationChannel
{
    private const ATTACH_CHUNK = 500;

    /**
     * Validates a create payload (`name`, `type`, `config`, `secrets`, `is_default`, `active`, `templates`,
     * `apply_to_existing`) and returns the values `handle` takes.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(Workspace $workspace, array $input): array
    {
        $request = NotificationChannelRequest::create('/', 'POST', self::normalize($input));
        $request->setContainer(app())->setRedirector(app(Redirector::class));
        $request->validateResolved();

        return $request->validatedChannel();
    }

    /**
     * Persists a validated channel with its audit row. `apply_to_existing` attaches it to every monitor of
     * the workspace. A null actor records the caller of the current request, or the system outside one.
     *
     * @param  array<string, mixed>  $validated  The result of `validate`.
     */
    public function handle(Workspace $workspace, array $validated, ?User $actor): NotificationChannel
    {
        $applyToExisting = (bool) ($validated['apply_to_existing'] ?? false);
        unset($validated['apply_to_existing']);

        return DB::transaction(function () use ($workspace, $validated, $applyToExisting, $actor): NotificationChannel {
            $channel = $workspace->notificationChannels()->create($validated);

            $changes = [
                'name' => [null, $channel->name],
                'type' => [null, $channel->type->value],
                'config' => [null, $channel->config],
                'secret_keys' => [[], $this->secretKeys($channel->secrets)],
                'templates' => [null, $channel->templates],
                'is_default' => [null, $channel->is_default],
                'active' => [null, $channel->active],
            ];
            if ($applyToExisting && ($attached = $this->attachToMonitors($workspace, $channel)) > 0) {
                $changes['applied_to_existing'] = [null, $attached];
            }
            Audit::record('notification_channel.created', $channel, $changes, $actor, $workspace);

            return $channel;
        });
    }

    /**
     * Attaches the channel to every monitor of the workspace that does not have it yet.
     *
     * @return int The number of monitors newly attached.
     */
    public function attachToMonitors(Workspace $workspace, NotificationChannel $channel): int
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
     * Applies what the HTTP middleware does to a request body: strings are trimmed and empty ones become null.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    private static function normalize(array $input): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $out[$key] = self::normalize($value);
            } elseif (is_string($value)) {
                $value = trim($value);
                $out[$key] = $value === '' ? null : $value;
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
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
