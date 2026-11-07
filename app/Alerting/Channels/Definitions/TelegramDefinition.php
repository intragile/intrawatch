<?php

namespace App\Alerting\Channels\Definitions;

use App\Enums\ChannelType;

class TelegramDefinition extends AbstractChannelDefinition
{
    public function type(): ChannelType
    {
        return ChannelType::Telegram;
    }

    public function configRules(): array
    {
        return [
            'chat_id' => ['required', 'string', "regex:/^(-?\d{1,20}|@[A-Za-z0-9_]{5,32})$/"],
            'message_thread_id' => ['nullable', 'integer', 'min:1'],
            'server_url' => $this->urlRule(),
            'silent' => ['boolean'],
        ];
    }

    public function secretRules(): array
    {
        return ['bot_token' => ['required', 'string', "regex:/^\d+:[A-Za-z0-9_-]{30,}$/"]];
    }

    public function defaults(): array
    {
        return ['message_thread_id' => null, 'server_url' => 'https://api.telegram.org', 'silent' => false];
    }

    public function requiredSecrets(): array
    {
        return ['bot_token'];
    }
}
