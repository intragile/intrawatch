<?php

namespace App\Alerting\Channels\Senders;

use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Messages\OutgoingNotification;
use App\Alerting\Messages\TextLimit;
use App\Alerting\Transport\DeliveryFailure;
use App\Alerting\Transport\Redactor;

final class TelegramSender extends PushServiceSender
{
    public function send(ChannelSpec $channel, OutgoingNotification $n, string $idempotencyKey): void
    {
        $token = self::requireSecret($channel, 'bot_token');
        $chatId = $channel->configString('chat_id');
        if ($chatId === null) {
            throw new DeliveryFailure('Invalid channel configuration', permanent: true);
        }
        $server = rtrim($channel->configString('server_url') ?? 'https://api.telegram.org', '/');

        $payload = [
            'chat_id' => $chatId,
            'text' => TextLimit::cut($n->title."\n".$n->body, 4096),
            'disable_notification' => ($channel->config['silent'] ?? false) === true,
            'link_preview_options' => ['is_disabled' => true],
        ];
        $thread = self::configInt($channel, 'message_thread_id');
        if ($thread !== null) {
            $payload['message_thread_id'] = $thread;
        }

        try {
            $this->http->send('POST', "{$server}/bot{$token}/sendMessage", ['json' => $payload]);
        } catch (DeliveryFailure $e) {
            $decoded = $e->responseBody !== null ? json_decode($e->responseBody, true) : null;
            $description = is_array($decoded) ? ($decoded['description'] ?? null) : null;
            if (! is_string($description) || $description === '') {
                throw new DeliveryFailure($e->reason, $e->permanent, $e->retryAfter);
            }

            throw new DeliveryFailure(
                $e->reason,
                $e->permanent,
                $e->retryAfter,
                TextLimit::cut(Redactor::redact($description, $channel->secrets), 300),
            );
        }
    }
}
