<?php

namespace App\Events;

use App\Monitoring\Engine\PostCommit;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Alerts were recorded for monitors of one workspace, suppressed ones included.
 */
class AlertRaised implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    /**
     * @param  list<int>  $alertEventIds
     */
    public function __construct(
        public readonly int $workspaceId,
        public readonly array $alertEventIds,
    ) {}

    /**
     * Broadcast through the batched job of PostCommit when dispatched from there.
     */
    public function broadcastWhen(): bool
    {
        return PostCommit::broadcastsSeparately();
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("workspace.{$this->workspaceId}")];
    }

    public function broadcastAs(): string
    {
        return 'alert.raised';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['alert_event_ids' => $this->alertEventIds];
    }
}
