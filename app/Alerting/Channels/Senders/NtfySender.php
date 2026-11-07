<?php

namespace App\Alerting\Channels\Senders;

use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Messages\OutgoingNotification;
use App\Alerting\Messages\TextLimit;
use App\Alerting\Transport\DeliveryFailure;

final class NtfySender extends PushServiceSender
{
    private const TAGS = ['critical' => 'rotating_light', 'warning' => 'warning', 'ok' => 'white_check_mark'];

    public function send(ChannelSpec $channel, OutgoingNotification $n, string $idempotencyKey): void
    {
        $topic = $channel->configString('topic');
        if ($topic === null) {
            throw new DeliveryFailure('Invalid channel configuration', permanent: true);
        }
        $server = rtrim($channel->configString('server_url') ?? 'https://ntfy.sh', '/');

        $headers = [
            'Title' => self::headerValue($n->title),
            'Priority' => (string) (self::configInt($channel, 'priority')
                ?? self::bySeverity($n->severity, ['critical' => 5, 'warning' => 4, 'ok' => 3, 'info' => 3])),
            'Content-Type' => 'text/plain; charset=utf-8',
        ];
        if (isset(self::TAGS[$n->severity])) {
            $headers['Tags'] = self::TAGS[$n->severity];
        }
        $click = $n->digest ? '' : ($n->items[0]->url ?? '');
        if ($click !== '') {
            $headers['Click'] = self::headerValue($click);
        }
        $token = $channel->secretString('access_token');
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $this->http->send('POST', "{$server}/".rawurlencode($topic), [
            'body' => TextLimit::cut($n->body, 4096),
            'headers' => $headers,
        ]);
    }
}
