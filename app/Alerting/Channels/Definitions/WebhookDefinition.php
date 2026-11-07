<?php

namespace App\Alerting\Channels\Definitions;

use App\Enums\ChannelType;

class WebhookDefinition extends AbstractChannelDefinition
{
    public function type(): ChannelType
    {
        return ChannelType::Webhook;
    }

    public function configRules(): array
    {
        return [
            'url' => $this->urlRule(),
            'method' => ['required', 'in:POST,PUT'],
            'format' => ['required', 'in:json,form'],
            'headers' => ['array', 'max:20'],
            'headers.*' => ['string', 'max:1024'],
        ];
    }

    public function secretRules(): array
    {
        return [
            'secret_headers' => ['nullable', 'array', 'max:20'],
            'secret_headers.*' => ['string', 'max:1024'],
            'signing_secret' => ['nullable', 'string', 'min:16', 'max:256'],
        ];
    }

    public function defaults(): array
    {
        return ['method' => 'POST', 'format' => 'json', 'headers' => []];
    }

    public function supportsDigest(): bool
    {
        return false;
    }
}
