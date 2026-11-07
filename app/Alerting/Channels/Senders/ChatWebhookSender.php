<?php

namespace App\Alerting\Channels\Senders;

use App\Alerting\Channels\ChannelSender;
use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Messages\OutgoingNotification;
use App\Alerting\Transport\DeliveryFailure;
use App\Alerting\Transport\OutboundHttp;

/**
 * Base for incoming-webhook chat services: one JSON POST to the secret webhook URL.
 */
abstract class ChatWebhookSender implements ChannelSender
{
    public function __construct(protected readonly OutboundHttp $http) {}

    /**
     * @return array<string, mixed>
     */
    abstract protected function payload(ChannelSpec $channel, OutgoingNotification $n): array;

    protected function url(ChannelSpec $channel): string
    {
        $url = $channel->secretString('webhook_url');
        if ($url === null) {
            throw new DeliveryFailure('Missing webhook URL', permanent: true);
        }

        return $url;
    }

    public function send(ChannelSpec $channel, OutgoingNotification $n, string $idempotencyKey): void
    {
        $this->http->send('POST', $this->url($channel), [
            'json' => $this->payload($channel, $n),
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    protected static function text(OutgoingNotification $n): string
    {
        return $n->title."\n".$n->body;
    }

    /**
     * Escapes the control characters of Slack-style markup so check output stays inert text.
     */
    protected static function escapeMarkup(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
