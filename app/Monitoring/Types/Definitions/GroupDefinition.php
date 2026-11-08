<?php

namespace App\Monitoring\Types\Definitions;

use App\Enums\MonitorType;
use Illuminate\Validation\Validator;

class GroupDefinition extends AbstractDefinition
{
    public function type(): MonitorType
    {
        return MonitorType::Group;
    }

    public function destinationKeys(): array
    {
        // Evaluated from its children; nothing is contacted.
        return [];
    }

    public function configRules(): array
    {
        return [
            'mode' => ['required', 'in:all,any,quorum'],
            'quorum' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function defaults(): array
    {
        return ['mode' => 'all', 'quorum' => null];
    }

    public function supportsInverted(): bool
    {
        return false;
    }

    public function isScheduled(): bool
    {
        return false;
    }

    public function afterValidation(array $config, Validator $v): void
    {
        if (($config['mode'] ?? null) === 'quorum' && ($config['quorum'] ?? null) === null) {
            $v->errors()->add('config.quorum', 'The quorum is required when the mode is quorum.');
        }
    }
}
