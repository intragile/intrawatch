<?php

namespace App\Events;

use App\Enums\MonitorStatus;
use App\Monitoring\Engine\PostCommit;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A monitor's effective status changed.
 */
class MonitorStatusChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $monitorId,
        public readonly MonitorStatus $from,
        public readonly MonitorStatus $to,
        public readonly CarbonImmutable $at,
        public readonly CarbonImmutable $previousSince,
        public readonly string $message,
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
        return 'monitor.status_changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'monitor_id' => $this->monitorId,
            'from' => $this->from->value,
            'to' => $this->to->value,
            'at' => $this->at->toIso8601ZuluString(),
            'previous_since' => $this->previousSince->toIso8601ZuluString(),
            'message' => $this->message,
        ];
    }
}
