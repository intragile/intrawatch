<?php

namespace App\Http\Requests;

use App\Enums\MonitorType;
use App\Models\Monitor;

class UpdateMonitorRequest extends MonitorRequest
{
    protected function stored(): ?Monitor
    {
        $monitor = $this->route('monitor');

        return $monitor instanceof Monitor ? $monitor : null;
    }

    protected function monitorType(): ?MonitorType
    {
        return $this->stored()?->type;
    }

    protected function required(): string
    {
        return 'sometimes';
    }

    protected function ownRules(MonitorType $type): array
    {
        return ['type' => ['sometimes', function (string $attribute, mixed $value, \Closure $fail) use ($type): void {
            if ($value !== $type->value) {
                $fail('The type of a monitor cannot be changed.');
            }
        }]];
    }

    /**
     * Only the columns the request touches, plus the merged config and secrets.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $columns = $this->columns();

        $changes = [];
        foreach ($columns as $column => $value) {
            if ($column === 'config' || $column === 'secrets' || $this->has($column)) {
                $changes[$column] = $value;
            }
        }

        return $changes;
    }
}
