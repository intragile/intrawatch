<?php

namespace App\Monitoring\Types\Definitions;

use App\Enums\MonitorType;
use Cron\CronExpression;

class CronDefinition extends AbstractDefinition
{
    public function type(): MonitorType
    {
        return MonitorType::Cron;
    }

    public function destinationKeys(): array
    {
        // Nothing is contacted: the monitor is fed from outside.
        return [];
    }

    public function configRules(): array
    {
        return [
            'schedule' => ['required', 'string', 'max:255', fn (string $a, mixed $v, \Closure $fail) => is_string($v) && CronExpression::isValidExpression($v) ?: $fail('The :attribute must be a valid cron expression.')],
            'timezone' => ['nullable', 'string', 'timezone:all'],
            'grace_seconds' => ['required', 'integer', 'between:0,86400'],
            'max_runtime_seconds' => ['nullable', 'integer', 'between:1,86400'],
        ];
    }

    /**
     * The timezone stays null here: its default is the workspace timezone, which the caller supplies.
     */
    public function defaults(): array
    {
        return ['timezone' => null, 'grace_seconds' => 300, 'max_runtime_seconds' => null];
    }

    public function supportsInverted(): bool
    {
        return false;
    }

    public function isScheduled(): bool
    {
        return false;
    }
}
