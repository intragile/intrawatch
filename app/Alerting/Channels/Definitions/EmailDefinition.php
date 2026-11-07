<?php

namespace App\Alerting\Channels\Definitions;

use App\Enums\ChannelType;

class EmailDefinition extends AbstractChannelDefinition
{
    public function type(): ChannelType
    {
        return ChannelType::Email;
    }

    public const SMTP_DEFAULT_PORT = 587;

    public const SMTP_DEFAULT_ENCRYPTION = 'starttls';

    public function configRules(): array
    {
        return [
            'to' => ['required', 'array', 'min:1', 'max:20'],
            'to.*' => ['required', 'string', 'email', 'max:254'],
            // Null selects the application mailer. Port and encryption fall back to the constants above
            // when omitted, because defaults are merged one level deep only.
            'smtp' => ['nullable', 'array'],
            'smtp.host' => ['required_with:config.smtp', 'string', 'max:255'],
            'smtp.port' => ['nullable', 'integer', 'between:1,65535'],
            'smtp.encryption' => ['nullable', 'in:starttls,tls,none'],
            'smtp.username' => ['nullable', 'string', 'max:255'],
            'smtp.from_address' => ['required_with:config.smtp', 'string', 'email', 'max:254'],
            'smtp.from_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function secretRules(): array
    {
        return ['smtp_password' => ['nullable', 'string', 'max:1024']];
    }

    public function defaults(): array
    {
        return ['smtp' => null];
    }
}
