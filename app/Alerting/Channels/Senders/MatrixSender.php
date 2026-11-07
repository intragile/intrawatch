<?php

namespace App\Alerting\Channels\Senders;

use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Messages\OutgoingNotification;
use App\Alerting\Messages\TextLimit;
use App\Alerting\Transport\DeliveryFailure;

final class MatrixSender extends PushServiceSender
{
    public function send(ChannelSpec $channel, OutgoingNotification $n, string $idempotencyKey): void
    {
        $server = self::baseUrl($channel, 'homeserver_url');
        $room = $channel->configString('room_id');
        if ($room === null) {
            throw new DeliveryFailure('Invalid channel configuration', permanent: true);
        }
        $token = self::requireSecret($channel, 'access_token');

        // The transaction id makes a retried send idempotent on the homeserver.
        $path = '/_matrix/client/v3/rooms/'.rawurlencode($room).'/send/m.room.message/'.rawurlencode($idempotencyKey);

        $this->http->send('PUT', $server.$path, [
            'json' => ['msgtype' => 'm.text', 'body' => TextLimit::cut($n->title."\n".$n->body, 16000)],
            'headers' => ['Authorization' => 'Bearer '.$token],
        ]);
    }
}
