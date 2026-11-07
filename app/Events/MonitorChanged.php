<?php

namespace App\Events;

use App\Monitoring\Engine\PostCommit;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A watched value of a monitor, such as its DNS records or certificate fingerprint, changed.
 */
class MonitorChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $monitorId,
        public readonly string $key,
        public readonly ?string $old,
        public readonly string $new,
        public readonly CarbonImmutable $at,
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
        return 'monitor.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'monitor_id' => $this->monitorId,
            'key' => $this->key,
            'old' => $this->old,
            'new' => $this->new,
            'at' => $this->at->toIso8601ZuluString(),
        ];
    }
}
