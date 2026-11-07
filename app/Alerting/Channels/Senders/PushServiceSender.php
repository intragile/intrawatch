<?php

namespace App\Alerting\Channels\Senders;

use App\Alerting\Channels\ChannelSender;
use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Transport\DeliveryFailure;
use App\Alerting\Transport\OutboundHttp;

/**
 * Shared plumbing for senders that talk to one provider API over HTTP.
 */
abstract class PushServiceSender implements ChannelSender
{
    public function __construct(protected readonly OutboundHttp $http) {}

    /**
     * The configured base URL without a trailing slash.
     */
    protected static function baseUrl(ChannelSpec $channel, string $key = 'server_url'): string
    {
        $url = $channel->configString($key);
        if ($url === null) {
            throw new DeliveryFailure('Invalid channel configuration', permanent: true);
        }

        return rtrim($url, '/');
    }

    protected static function requireSecret(ChannelSpec $channel, string $key): string
    {
        $value = $channel->secretString($key);
        if ($value === null) {
            throw new DeliveryFailure('Missing channel credentials', permanent: true);
        }

        return $value;
    }

    /**
     * Picks the value for a severity; unknown severities use the info value.
     *
     * @param  array{critical: int, warning: int, ok: int, info: int}  $map
     */
    protected static function bySeverity(string $severity, array $map): int
    {
        return $map[$severity] ?? $map['info'];
    }

    /**
     * Header values must be a single line of printable text.
     */
    protected static function headerValue(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));
    }

    protected static function configInt(ChannelSpec $channel, string $key): ?int
    {
        $value = $channel->config[$key] ?? null;

        return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) ? (int) $value : null;
    }
}
