<?php

namespace App\Alerting\Channels\Senders;

use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Messages\OutgoingNotification;

final class GoogleChatSender extends ChatWebhookSender
{
    protected function payload(ChannelSpec $channel, OutgoingNotification $n): array
    {
        return ['text' => self::text($n)];
    }
}
