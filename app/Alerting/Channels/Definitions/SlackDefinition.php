<?php

namespace App\Alerting\Channels\Definitions;

use App\Enums\ChannelType;

class SlackDefinition extends AbstractChannelDefinition
{
    public function type(): ChannelType
    {
        return ChannelType::Slack;
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
