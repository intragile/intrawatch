<?php

namespace App\Http\Requests;

use App\Enums\MonitorType;
use App\Models\Monitor;
use Illuminate\Validation\Rule;

/**
 * @phpstan-type ValidatedMonitor array{attributes: array<string, mixed>, tags: array<int, array{value: string|null}>|null, notification_channel_ids: list<int>|null, parent_id: int|null, push_token?: string}
 */
class StoreMonitorRequest extends MonitorRequest
{
    protected function stored(): ?Monitor
    {
        return null;
    }

    protected function monitorType(): ?MonitorType
    {
        $type = $this->input('type');

        return is_string($type) ? MonitorType::tryFrom($type) : null;
    }

    protected function ownRules(MonitorType $type): array
    {
        return ['type' => ['required', Rule::enum(MonitorType::class)]];
    }

    /**
     * Column values for a new monitor, with the engine defaults applied.
     *
     * @return array<string, mixed>
     */
    public function monitorAttributes(): array
    {
        $columns = $this->columns();
        $type = $this->monitorType() ?? MonitorType::Http;
        $interval = is_numeric($columns['interval_seconds'] ?? null)
            ? (int) $columns['interval_seconds']
            : $type->definition()->defaultIntervalSeconds();

        return $columns + [
            'description' => null,
            'active' => true,
            'inverted' => false,
            'degraded_threshold_ms' => null,
            'parent_id' => null,
        ] + [
            'interval_seconds' => $interval,
            'timeout_seconds' => config()->integer('monitoring.limits.timeout.default'),
            'retries' => config()->integer('monitoring.limits.retries.default'),
            'retry_interval_seconds' => $interval,
        ] + ['type' => $type];
    }

    /**
     * Everything `CreateMonitor::handle` takes: the column values, the tag assignments and channel ids (null
     * when the request leaves them out, which binds the workspace's default channels) and the parent id.
     *
     * @return ValidatedMonitor
     */
    public function validatedMonitor(): array
    {
        return [
            'attributes' => $this->monitorAttributes(),
            'tags' => $this->tagAssignments(),
            'notification_channel_ids' => $this->notificationChannelIds(),
            'parent_id' => $this->parentId(),
        ];
    }
}
