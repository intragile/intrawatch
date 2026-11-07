<?php

namespace App\Alerting\Channels\Senders;

use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Messages\OutgoingNotification;

final class TeamsSender extends ChatWebhookSender
{
    protected function payload(ChannelSpec $channel, OutgoingNotification $n): array
    {
        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => [
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => [
                        ['type' => 'TextBlock', 'text' => $n->title, 'weight' => 'Bolder', 'wrap' => true],
                        ['type' => 'TextBlock', 'text' => $n->body, 'wrap' => true],
                    ],
                ],
            ]],
        ];
    }
}
