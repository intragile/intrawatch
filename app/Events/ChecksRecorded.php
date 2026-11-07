<?php

namespace App\Events;

use App\Monitoring\Engine\PostCommit;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A batch of check results was stored for monitors of one workspace.
 */
class ChecksRecorded implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    /**
     * @param  list<array{monitor_id: int, status: string, latency_ms: ?int, checked_at: string}>  $checks
     */
    public function __construct(
        public readonly int $workspaceId,
        public readonly array $checks,
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
        return 'monitor.checks';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['checks' => $this->checks];
    }
}
