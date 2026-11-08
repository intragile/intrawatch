<?php

namespace App\Alerting\Channels\Definitions;

use App\Enums\ChannelType;

class TeamsDefinition extends AbstractChannelDefinition
{
    public function type(): ChannelType
    {
        return ChannelType::Teams;
    }

    public function destinationKeys(): array
    {
        // The secret is the destination itself.
        return [];
    }

    public function configRules(): array
    {
        return [];
    }

    public function secretRules(): array
    {
        return $this->webhookUrlSecretRules();
    }

    public function requiredSecrets(): array
    {
        return ['webhook_url'];
    }
}
