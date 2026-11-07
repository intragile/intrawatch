<?php

namespace App\Alerting\Channels\Definitions;

use App\Enums\ChannelType;
use Illuminate\Validation\Validator;

class AppriseDefinition extends AbstractChannelDefinition
{
    public function type(): ChannelType
    {
        return ChannelType::Apprise;
    }

    public function configRules(): array
    {
        return [
            'server_url' => $this->urlRule(),
            'config_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
        ];
    }

    public function secretRules(): array
    {
        return ['urls' => ['nullable', 'string', 'max:4096']];
    }

    public function defaults(): array
    {
        return ['config_key' => null];
    }

    public function afterValidation(array $config, array $secrets, Validator $v): void
    {
        $key = $config['config_key'] ?? null;
        $urls = $secrets['urls'] ?? null;
        if (($key === null || $key === '') && ($urls === null || $urls === '')) {
            $v->errors()->add('config.config_key', 'Either a config key or notification URLs are required.');
        }
    }
}
