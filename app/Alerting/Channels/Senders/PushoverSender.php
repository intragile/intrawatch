<?php

namespace App\Alerting\Channels\Senders;

use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Messages\OutgoingNotification;
use App\Alerting\Messages\TextLimit;

final class PushoverSender extends PushServiceSender
{
    private const ENDPOINT = 'https://api.pushover.net/1/messages.json';

    public function send(ChannelSpec $channel, OutgoingNotification $n, string $idempotencyKey): void
    {
        $form = [
            'token' => self::requireSecret($channel, 'app_token'),
            'user' => self::requireSecret($channel, 'user_key'),
            'title' => TextLimit::cut($n->title, 250),
            'message' => TextLimit::cut($n->body, 1024),
            'priority' => self::configInt($channel, 'priority') ?? ($n->severity === 'critical' ? 1 : 0),
        ];
        foreach (['sound', 'device'] as $key) {
            $value = $channel->configString($key);
            if ($value !== null) {
                $form[$key] = $value;
            }
        }
        $url = $n->digest ? '' : ($n->items[0]->url ?? '');
        if ($url !== '') {
            $form['url'] = $url;
        }

        $this->http->send('POST', self::ENDPOINT, ['form_params' => $form]);
    }
}
