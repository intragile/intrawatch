<?php

namespace App\Alerting\Channels\Senders;

use App\Alerting\Channels\ChannelSender;
use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Messages\AlertMessage;
use App\Alerting\Messages\OutgoingNotification;
use App\Alerting\Transport\DeliveryFailure;
use App\Alerting\Transport\OutboundHttp;

final class WebhookSender implements ChannelSender
{
    public function __construct(private readonly OutboundHttp $http) {}

    public function send(ChannelSpec $channel, OutgoingNotification $n, string $idempotencyKey): void
    {
        $url = $channel->configString('url');
        $item = $n->items[0] ?? null;
        if ($url === null || $item === null) {
            throw new DeliveryFailure('Invalid webhook configuration', permanent: true);
        }

        $payload = self::payload($n, $item);
        $form = ($channel->config['format'] ?? 'json') === 'form';
        $raw = $form
            ? http_build_query($payload)
            : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

        $headers = self::withoutFrameworkHeaders(
            self::stringMap($channel->config['headers'] ?? null) + self::stringMap($channel->secrets['secret_headers'] ?? null),
        );
        $headers['Content-Type'] = $form ? 'application/x-www-form-urlencoded' : 'application/json';
        $headers['X-IntraWatch-Event'] = $item->kind->value;
        $headers['X-IntraWatch-Delivery'] = $idempotencyKey;

        $signingSecret = $channel->secretString('signing_secret');
        if ($signingSecret !== null) {
            $t = time();
            $headers['X-IntraWatch-Signature'] = "t={$t},v1=".hash_hmac('sha256', "{$t}.{$raw}", $signingSecret);
        }

        $method = ($channel->config['method'] ?? 'POST') === 'PUT' ? 'PUT' : 'POST';
        $this->http->send($method, $url, ['body' => $raw, 'headers' => $headers]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(OutgoingNotification $n, AlertMessage $item): array
    {
        return [
            'event' => $item->kind->value,
            'severity' => $item->severity,
            'title' => $item->title,
            'body' => $item->body,
            'monitor' => [
                'id' => $item->monitorId,
                'name' => $item->monitorName,
                'type' => $item->monitorType,
                'target' => $item->target,
                'url' => $item->url,
            ],
            'status' => $item->status,
            'previous_status' => $item->previousStatus,
            'message' => $item->message,
            'at' => $item->at->utc()->format('Y-m-d\TH:i:s\Z'),
            'duration_seconds' => $item->durationSeconds,
            'changes' => array_map(
                fn (array $c): array => ['key' => $c['key'], 'old' => $c['old'], 'new' => $c['new']],
                $item->changes,
            ),
            'workspace' => ['id' => $item->workspaceId, 'name' => $item->workspaceName],
        ];
    }

    /**
     * Drops custom headers the sender sets itself, whatever their capitalisation.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private static function withoutFrameworkHeaders(array $headers): array
    {
        $reserved = ['content-type', 'x-intrawatch-event', 'x-intrawatch-delivery', 'x-intrawatch-signature'];

        return array_filter($headers, fn (string $name): bool => ! in_array(strtolower($name), $reserved, true), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $name => $v) {
            if (is_string($v)) {
                $out[(string) $name] = $v;
            }
        }

        return $out;
    }
}
