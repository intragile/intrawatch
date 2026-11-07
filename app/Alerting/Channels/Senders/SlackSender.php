<?php

namespace App\Alerting\Channels\Senders;

use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Messages\OutgoingNotification;

final class SlackSender extends ChatWebhookSender
{
    protected function payload(ChannelSpec $channel, OutgoingNotification $n): array
    {
        return ['text' => self::escapeMarkup(self::text($n))];
    }
}
