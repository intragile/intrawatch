<?php

namespace App\Alerting\Channels\Senders;

use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Messages\OutgoingNotification;

final class GoogleChatSender extends ChatWebhookSender
{
    protected function payload(ChannelSpec $channel, OutgoingNotification $n): array
    {
        $escaped = str_replace(['<', '>'], ['&lt;', '&gt;'], self::text($n));

        // A zero-width space before each formatting character keeps check output from rendering as markup.
        return ['text' => (string) preg_replace('/[*_~`]/', "\u{200B}$0", $escaped)];
    }
}
