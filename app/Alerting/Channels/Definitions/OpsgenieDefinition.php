<?php

namespace App\Alerting\Channels\Definitions;

use App\Enums\ChannelType;

class OpsgenieDefinition extends AbstractChannelDefinition
{
    public function type(): ChannelType
    {
        return ChannelType::Opsgenie;
    }

    public function configRules(): array
    {
        return [
            'region' => ['required', 'in:us,eu'],
            'priority' => ['nullable', 'in:P1,P2,P3,P4,P5'],
        ];
    }

    public function secretRules(): array
    {
        return ['api_key' => ['required', 'string', 'max:256']];
    }

    public function defaults(): array
    {
        return ['region' => 'us', 'priority' => null];
    }

    public function requiredSecrets(): array
    {
        return ['api_key'];
    }

    public function supportsDigest(): bool
    {
        return false;
    }
}
