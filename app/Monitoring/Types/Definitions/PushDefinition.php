<?php

namespace App\Monitoring\Types\Definitions;

use App\Enums\MonitorType;

class PushDefinition extends AbstractDefinition
{
    public function type(): MonitorType
    {
        return MonitorType::Push;
    }

    public function destinationKeys(): array
    {
        // Nothing is contacted: the monitor is fed from outside.
        return [];
    }

    public function configRules(): array
    {
        return [
            'grace_seconds' => ['required', 'integer', 'between:0,3600'],
        ];
    }

    public function defaults(): array
    {
        return ['grace_seconds' => 30];
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
