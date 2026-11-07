<?php

namespace App\Alerting\Channels\Senders;

use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Messages\OutgoingNotification;
use App\Alerting\Transport\DeliveryFailure;

final class AppriseSender extends PushServiceSender
{
    public function send(ChannelSpec $channel, OutgoingNotification $n, string $idempotencyKey): void
    {
        $server = self::baseUrl($channel);
        $key = $channel->configString('config_key');
        $urls = $channel->secretString('urls');
        if ($key === null && $urls === null) {
            throw new DeliveryFailure('Invalid channel configuration', permanent: true);
        }

        $payload = [
            'title' => $n->title,
            'body' => $n->body,
            'type' => match ($n->severity) {
                'critical' => 'failure',
                'warning' => 'warning',
                'ok' => 'success',
                default => 'info',
            },
        ];

        if ($key !== null) {
            $url = "{$server}/notify/".rawurlencode($key);
        } else {
            $url = "{$server}/notify/";
            $payload['urls'] = $urls;
        }

        $this->http->send('POST', $url, ['json' => $payload]);
    }
}
