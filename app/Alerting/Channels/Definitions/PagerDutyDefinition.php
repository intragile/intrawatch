<?php

namespace App\Alerting\Channels\Definitions;

use App\Enums\ChannelType;

class PagerDutyDefinition extends AbstractChannelDefinition
{
    public function type(): ChannelType
    {
        return ChannelType::PagerDuty;
    }

    public function destinationKeys(): array
    {
        // Fixed API host.
        return [];
    }

    public function configRules(): array
    {
        return [];
    }

    public function secretRules(): array
    {
        return ['routing_key' => ['required', 'string', 'size:32']];
    }

    public function requiredSecrets(): array
    {
        return ['routing_key'];
    }

    public function supportsDigest(): bool
    {
        return false;
    }
}
