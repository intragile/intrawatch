<?php

namespace App\Alerting\Channels\Definitions;

use App\Enums\ChannelType;

class PushoverDefinition extends AbstractChannelDefinition
{
    public function type(): ChannelType
    {
        return ChannelType::Pushover;
    }

    public function configRules(): array
    {
        return [
            'priority' => ['nullable', 'integer', 'between:-2,1'],
            'sound' => ['nullable', 'string', 'max:32'],
            'device' => ['nullable', 'string', 'max:25'],
        ];
    }

    public function secretRules(): array
    {
        return [
            'user_key' => ['required', 'string', 'max:128'],
            'app_token' => ['required', 'string', 'max:128'],
        ];
    }

    public function defaults(): array
    {
        return ['priority' => null, 'sound' => null, 'device' => null];
    }

    public function requiredSecrets(): array
    {
        return ['user_key', 'app_token'];
    }
}
