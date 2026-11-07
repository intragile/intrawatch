<?php

namespace App\Http\Controllers\Api;

use App\Alerting\TestSender;
use App\Http\Controllers\Controller;
use App\Http\Requests\NotificationChannelRequest;
use App\Models\NotificationChannel;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;

/**
 * Synchronous test sends. They write no audit row, no delivery row and leave the channel health untouched.
 */
class NotificationChannelTestController extends Controller
{
    public function __construct(private readonly TestSender $tester) {}

    /**
     * Sends a test notification through a saved channel.
     */
    public function saved(Workspace $workspace, NotificationChannel $channel): JsonResponse
    {
        return response()->json(['data' => $this->tester->send($channel)]);
    }

    /**
     * Sends a test notification through a channel described by the request body, which is not saved.
     */
    public function draft(NotificationChannelRequest $request, Workspace $workspace): JsonResponse
    {
        $channel = new NotificationChannel($request->channelAttributes() + ['workspace_id' => $workspace->id]);

        return response()->json(['data' => $this->tester->send($channel)]);
    }
}
