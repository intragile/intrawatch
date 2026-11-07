<?php

namespace App\Alerting\Channels\Senders;

use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Messages\AlertMessage;
use App\Alerting\Messages\OutgoingNotification;
use App\Alerting\Messages\TextLimit;
use App\Alerting\Transport\DeliveryFailure;
use App\Enums\AlertKind;

final class PagerDutySender extends IncidentToolSender
{
    private const ENDPOINT = 'https://events.pagerduty.com/v2/enqueue';

    private const CHANGE_ENDPOINT = 'https://events.pagerduty.com/v2/change/enqueue';

    public function send(ChannelSpec $channel, OutgoingNotification $n, string $idempotencyKey): void
    {
        $routingKey = self::requireSecret($channel, 'routing_key');
        $item = $n->items[0] ?? null;
        if ($item === null) {
            throw new DeliveryFailure('Invalid notification', permanent: true);
        }

        if ($item->kind === AlertKind::Test) {
            $key = "iw-test-{$idempotencyKey}";
            $this->event($routingKey, 'trigger', $key, $item, 'info');
            $this->event($routingKey, 'resolve', $key, $item, 'info');

            return;
        }

        if ($item->kind === AlertKind::Changed) {
            $this->changeEvent($routingKey, $item);

            return;
        }

        $opens = self::opens($item);
        $severity = self::severity($item) === 'critical' ? 'critical' : 'warning';
        $this->event($routingKey, $opens ? 'trigger' : 'resolve', self::incidentKey($item), $item, $severity);
    }

    private function changeEvent(string $routingKey, AlertMessage $item): void
    {
        $this->http->send('POST', self::CHANGE_ENDPOINT, ['json' => [
            'routing_key' => $routingKey,
            'payload' => [
                'summary' => TextLimit::cut($item->title, 1024),
                'source' => $item->target !== '' ? $item->target : $item->monitorName,
                'timestamp' => $item->at->utc()->format('Y-m-d\TH:i:s\Z'),
                'custom_details' => ['changes' => $item->changes],
            ],
            'links' => [['href' => $item->url, 'text' => 'IntraWatch']],
        ]]);
    }

    private function event(string $routingKey, string $action, string $dedupKey, AlertMessage $item, string $severity): void
    {
        $event = ['routing_key' => $routingKey, 'event_action' => $action, 'dedup_key' => $dedupKey];
        if ($action === 'trigger') {
            $event['payload'] = [
                'summary' => TextLimit::cut($item->title, 1024),
                'source' => $item->target !== '' ? $item->target : $item->monitorName,
                'severity' => $severity,
            ];
        }

        $this->http->send('POST', self::ENDPOINT, ['json' => $event]);
    }
}
