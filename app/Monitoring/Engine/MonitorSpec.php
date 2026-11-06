<?php

namespace App\Monitoring\Engine;

use App\Enums\MonitorType;
use App\Models\Monitor;

/**
 * Immutable snapshot of everything a check needs to know about a monitor. Checkers receive this, never the
 * model, so they cannot touch the database from inside the event loop.
 */
final readonly class MonitorSpec
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $secrets
     * @param  int  $updatedAt  Unix timestamp of the monitor's last modification.
     */
    public function __construct(
        public int $id,
        public int $workspaceId,
        public MonitorType $type,
        public array $config,
        public array $secrets,
        public int $intervalSeconds,
        public int $timeoutSeconds,
        public int $retries,
        public int $retryIntervalSeconds,
        public ?int $degradedThresholdMs,
        public bool $inverted,
        public ?int $parentId,
        public int $updatedAt,
        public ?string $pushToken,
    ) {}

    public static function fromModel(Monitor $monitor): self
    {
        return new self(
            id: $monitor->id,
            workspaceId: $monitor->workspace_id,
            type: $monitor->type,
            config: $monitor->config,
            secrets: $monitor->secrets ?? [],
            intervalSeconds: $monitor->interval_seconds,
            timeoutSeconds: $monitor->timeout_seconds,
            retries: $monitor->retries,
            retryIntervalSeconds: $monitor->retry_interval_seconds,
            degradedThresholdMs: $monitor->degraded_threshold_ms,
            inverted: $monitor->inverted,
            parentId: $monitor->parent_id,
            updatedAt: $monitor->updated_at?->getTimestamp() ?? 0,
            pushToken: $monitor->push_token,
        );
    }
}
