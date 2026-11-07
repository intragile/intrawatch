<?php

namespace App\Alerting\Channels\Definitions;

use App\Enums\ChannelType;

class GotifyDefinition extends AbstractChannelDefinition
{
    public function type(): ChannelType
    {
        return ChannelType::Gotify;
    }

    public function configRules(): array
    {
        return [
            'server_url' => $this->urlRule(),
            'priority' => ['nullable', 'integer', 'between:0,10'],
        ];
    }

    public function secretRules(): array
    {
        return ['app_token' => ['required', 'string', 'max:512']];
    }

    public function defaults(): array
    {
        return ['priority' => null];
    }

    public function requiredSecrets(): array
    {
        return ['app_token'];
    }
}
