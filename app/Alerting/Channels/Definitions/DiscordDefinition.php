<?php

namespace App\Alerting\Channels\Definitions;

use App\Enums\ChannelType;

class DiscordDefinition extends AbstractChannelDefinition
{
    public function type(): ChannelType
    {
        return ChannelType::Discord;
    }

    public function configRules(): array
    {
        return [
            'username' => ['nullable', 'string', 'max:80'],
            'thread_id' => ['nullable', 'string', "regex:/^\d+\z/"],
        ];
    }

    public function defaults(): array
    {
        return ['username' => null, 'thread_id' => null];
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
