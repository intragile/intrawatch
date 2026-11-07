<?php

namespace App\Alerting\Channels\Senders;

use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Messages\OutgoingNotification;

final class GotifySender extends PushServiceSender
{
    public function send(ChannelSpec $channel, OutgoingNotification $n, string $idempotencyKey): void
    {
        $server = self::baseUrl($channel);
        $token = self::requireSecret($channel, 'app_token');

        $this->http->send('POST', "{$server}/message", [
            'json' => [
                'title' => $n->title,
                'message' => $n->body,
                'priority' => self::configInt($channel, 'priority')
                    ?? self::bySeverity($n->severity, ['critical' => 8, 'warning' => 5, 'ok' => 2, 'info' => 2]),
            ],
            'headers' => ['X-Gotify-Key' => $token],
        ]);
    }
}
