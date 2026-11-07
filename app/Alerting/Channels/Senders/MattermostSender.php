<?php

namespace App\Alerting\Channels\Senders;

use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Messages\OutgoingNotification;

final class MattermostSender extends ChatWebhookSender
{
    protected function payload(ChannelSpec $channel, OutgoingNotification $n): array
    {
        $payload = ['text' => self::escapeMarkup(self::text($n))];
        foreach (['channel', 'username'] as $key) {
            $value = $channel->configString($key);
            if ($value !== null) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }
}
