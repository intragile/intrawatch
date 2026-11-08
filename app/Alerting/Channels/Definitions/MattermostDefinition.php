<?php

namespace App\Alerting\Channels\Definitions;

use App\Enums\ChannelType;

class MattermostDefinition extends AbstractChannelDefinition
{
    public function type(): ChannelType
    {
        return ChannelType::Mattermost;
    }

    public function destinationKeys(): array
    {
        // The secret is the destination itself.
        return [];
    }

    public function configRules(): array
    {
        return [
            'channel' => ['nullable', 'string', 'max:80'],
            'username' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function defaults(): array
    {
        return ['channel' => null, 'username' => null];
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
