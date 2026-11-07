<?php

namespace App\Alerting\Channels\Senders;

use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Messages\AlertMessage;
use App\Alerting\Messages\OutgoingNotification;
use App\Alerting\Messages\TextLimit;
use App\Alerting\Transport\DeliveryFailure;
use App\Enums\AlertKind;

final class OpsgenieSender extends IncidentToolSender
{
    public function send(ChannelSpec $channel, OutgoingNotification $n, string $idempotencyKey): void
    {
        $apiKey = self::requireSecret($channel, 'api_key');
        $item = $n->items[0] ?? null;
        if ($item === null) {
            throw new DeliveryFailure('Invalid notification', permanent: true);
        }
        $base = ($channel->config['region'] ?? 'us') === 'eu' ? 'https://api.eu.opsgenie.com' : 'https://api.opsgenie.com';
        $headers = ['Authorization' => 'GenieKey '.$apiKey];

        if ($item->kind === AlertKind::Test) {
            $alias = "iw-test-{$idempotencyKey}";
            $this->create($base, $headers, $alias, $item, $channel);
            $this->close($base, $headers, $alias);

            return;
        }

        $alias = self::incidentKey($item);
        if (self::opens($item)) {
            $this->create($base, $headers, $alias, $item, $channel);
        } else {
            $this->close($base, $headers, $alias);
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function create(string $base, array $headers, string $alias, AlertMessage $item, ChannelSpec $channel): void
    {
        $priority = $channel->configString('priority')
            ?? match (self::severity($item)) {
                'critical' => 'P1',
                'warning' => 'P3',
                default => 'P5',
            };

        $this->http->send('POST', "{$base}/v2/alerts", [
            'json' => [
                'message' => TextLimit::cut($item->title, 130),
                'alias' => $alias,
                'description' => TextLimit::cut($item->body, 15000),
                'priority' => $priority,
            ],
            'headers' => $headers,
        ]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function close(string $base, array $headers, string $alias): void
    {
        try {
            $this->http->send('POST', "{$base}/v2/alerts/".rawurlencode($alias).'/close?identifierType=alias', [
                'json' => ['source' => 'IntraWatch'],
                'headers' => $headers,
            ]);
        } catch (DeliveryFailure $e) {
            // Closing an alert that was never opened or is already closed leaves nothing to do.
            if ($e->reason !== 'HTTP 404') {
                throw $e;
            }
        }
    }
}
