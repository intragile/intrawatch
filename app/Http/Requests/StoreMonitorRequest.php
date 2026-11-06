<?php

namespace App\Http\Requests;

use App\Enums\MonitorType;
use App\Models\Monitor;
use Illuminate\Validation\Rule;

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
}
