<?php

namespace App\Alerting\Channels\Definitions;

use App\Enums\ChannelType;

class NtfyDefinition extends AbstractChannelDefinition
{
    public function type(): ChannelType
    {
        return ChannelType::Ntfy;
    }

    public function configRules(): array
    {
        return [
            'server_url' => $this->urlRule(),
            'topic' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}\z/'],
            'priority' => ['nullable', 'integer', 'between:1,5'],
        ];
    }

    public function secretRules(): array
    {
        return ['access_token' => ['nullable', 'string', 'max:512']];
    }

    public function defaults(): array
    {
        return ['server_url' => 'https://ntfy.sh', 'priority' => null];
    }
}
