<?php

namespace App\Alerting;

use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Channels\SenderRegistry;
use App\Alerting\Messages\MessageFactory;
use App\Alerting\Transport\DeliveryFailure;
use App\Alerting\Transport\Redactor;
use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends a test notification synchronously and reports the outcome. Nothing is persisted: no delivery row,
 * no channel health update.
 */
final class TestSender
{
    public function __construct(
        private readonly SenderRegistry $senders,
        private readonly MessageFactory $messages,
    ) {}

    /**
     * The channel may be unsaved. The error text never contains secret values.
     *
     * @return array{ok: bool, error: string|null}
     */
    public function send(NotificationChannel $channel): array
    {
        $spec = new ChannelSpec($channel->id ?? 0, $channel->workspace_id, $channel->type, $channel->config, $channel->secrets ?? []);

        try {
            $this->senders->for($channel->type)->send($spec, $this->messages->test($channel), 'iw-test-'.Str::uuid());

            return ['ok' => true, 'error' => null];
        } catch (DeliveryFailure $e) {
            return ['ok' => false, 'error' => Redactor::redact($e->message(), $spec->secrets)];
        } catch (\Throwable $e) {
            // The message may echo request data, so only the class is logged and nothing reaches the caller.
            Log::warning('Channel test send failed unexpectedly.', ['exception' => $e::class, 'channel_type' => $channel->type->value]);

            return ['ok' => false, 'error' => 'Unexpected error'];
        }
    }
}
