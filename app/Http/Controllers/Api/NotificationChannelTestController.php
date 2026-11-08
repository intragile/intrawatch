<?php

namespace App\Http\Controllers\Api;

use App\Alerting\TestSender;
use App\Http\Controllers\Controller;
use App\Http\Requests\NotificationChannelRequest;
use App\Models\NotificationChannel;
use App\Models\Workspace;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Synchronous test sends. They write no audit row, no delivery row and leave the channel health untouched.
 *
 * Besides the per-user `notification-test` route limit, a workspace shares one budget, so several members (or
 * tokens) together cannot turn test sends into a mass mailer.
 */
class NotificationChannelTestController extends Controller
{
    /** Test sends a workspace may make per minute, across all its members and tokens. */
    public const WORKSPACE_PER_MINUTE = 30;

    public function __construct(private readonly TestSender $tester) {}

    /**
     * Sends a test notification through a saved channel.
     */
    public function saved(Workspace $workspace, NotificationChannel $channel): JsonResponse
    {
        $this->throttleWorkspace($workspace);

        return response()->json(['data' => $this->tester->send($channel)]);
    }

    /**
     * Sends a test notification through a channel described by the request body, which is not saved.
     */
    public function draft(NotificationChannelRequest $request, Workspace $workspace): JsonResponse
    {
        $this->throttleWorkspace($workspace);
        $channel = new NotificationChannel($request->channelAttributes() + ['workspace_id' => $workspace->id]);

        return response()->json(['data' => $this->tester->send($channel)]);
    }

    private function throttleWorkspace(Workspace $workspace): void
    {
        $key = 'notification-test:workspace:'.$workspace->id;
        if (RateLimiter::tooManyAttempts($key, self::WORKSPACE_PER_MINUTE)) {
            throw new ThrottleRequestsException('Too many test sends in this workspace. Try again later.', headers: [
                'Retry-After' => RateLimiter::availableIn($key),
            ]);
        }
        RateLimiter::hit($key, 60);
    }
}
