<?php

namespace App\Alerting\Channels\Definitions;

use App\Enums\ChannelType;

class MatrixDefinition extends AbstractChannelDefinition
{
    public function type(): ChannelType
    {
        return ChannelType::Matrix;
    }

    public function configRules(): array
    {
        return [
            'homeserver_url' => $this->urlRule(),
            'room_id' => ['required', 'string', 'max:255', 'regex:/^![^:]+:.+\z/'],
        ];
    }

    public function secretRules(): array
    {
        return ['access_token' => ['required', 'string', 'max:1024']];
    }

    public function requiredSecrets(): array
    {
        return ['access_token'];
    }
}
