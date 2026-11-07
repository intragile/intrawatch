<?php

namespace App\Alerting\Channels;

use App\Alerting\Messages\OutgoingNotification;
use App\Alerting\Transport\DeliveryFailure;

interface ChannelSender
{
    /**
     * @param  string  $idempotencyKey  Stable per delivery ("iw-{deliveryId}"); receivers may use it to deduplicate.
     *
     * @throws DeliveryFailure
     */
    public function send(ChannelSpec $channel, OutgoingNotification $n, string $idempotencyKey): void;
}
