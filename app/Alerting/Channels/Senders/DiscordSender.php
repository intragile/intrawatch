<?php

namespace App\Alerting\Channels\Senders;

use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Messages\OutgoingNotification;
use App\Alerting\Messages\TextLimit;

final class DiscordSender extends ChatWebhookSender
{
    private const CONTENT_LIMIT = 2000;

    protected function payload(ChannelSpec $channel, OutgoingNotification $n): array
    {
        $payload = [
            'content' => TextLimit::cut(self::text($n), self::CONTENT_LIMIT),
            'allowed_mentions' => ['parse' => []],
        ];
        $username = $channel->configString('username');
        if ($username !== null) {
            $payload['username'] = $username;
        }

        return $payload;
    }

    protected function url(ChannelSpec $channel): string
    {
        $url = parent::url($channel);
        $thread = $channel->configString('thread_id');
        if ($thread === null) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'thread_id='.rawurlencode($thread);
    }
}
