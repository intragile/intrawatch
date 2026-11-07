<?php

namespace App\Alerting;

use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Channels\SenderRegistry;
use App\Alerting\Messages\MessageFactory;
use App\Alerting\Transport\DeliveryFailure;
use App\Alerting\Transport\Redactor;
use App\Enums\DeliveryStatus;
use App\Models\AlertDelivery;
use App\Models\AlertEvent;
use App\Models\NotificationChannel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Performs one attempt of an alert delivery and records its outcome on the delivery and the channel.
 *
 * Only the reason of a failure is stored: provider details are redacted against the channel's secrets and any
 * unexpected exception is reduced to its class name, since its message may echo request data.
 */
final class DeliveryRunner
{
    /** A delivery row that is not visible yet (or no longer) is looked up this many times before the send is dropped. */
    private const MISSING_ATTEMPTS = 3;

    private const MISSING_RETRY_SECONDS = 5;

    private const MAX_ERROR_LENGTH = 500;

    public function __construct(
        private readonly SenderRegistry $senders,
        private readonly MessageFactory $messages,
    ) {}

    /**
     * @param  int  $attempt  1-based attempt number of the queued job.
     *
     * @throws RetryDelivery When the delivery should be attempted again later.
     */
    public function run(int $deliveryId, int $attempt): void
    {
        $delivery = AlertDelivery::query()->find($deliveryId);
        if ($delivery === null) {
            // The row may not be committed yet (a queue outside the database) or may have been pruned.
            if ($attempt < self::MISSING_ATTEMPTS) {
                throw new RetryDelivery(self::MISSING_RETRY_SECONDS);
            }
            Log::warning('Alert delivery not found; the send is dropped.', ['delivery_id' => $deliveryId, 'attempt' => $attempt]);

            return;
        }

        // A duplicate job for a finished delivery.
        if ($delivery->status !== DeliveryStatus::Queued) {
            return;
        }

        $channel = $delivery->notification_channel_id === null
            ? null
            : NotificationChannel::query()->find($delivery->notification_channel_id);
        if ($channel === null) {
            $this->finish($delivery, DeliveryStatus::Failed, 'Channel removed');

            return;
        }
        if (! $channel->active) {
            $this->finish($delivery, DeliveryStatus::Failed, 'Channel disabled');

            return;
        }

        $events = AlertEvent::query()->whereIn('id', $delivery->alert_event_ids)->orderBy('id')->get();
        if ($events->isEmpty()) {
            $this->finish($delivery, DeliveryStatus::Failed, 'Alert events removed');

            return;
        }

        // Only the job that wins this claim sends; a concurrent duplicate that read the same row stops here.
        if (! $this->claim($delivery)) {
            return;
        }

        $spec = ChannelSpec::fromModel($channel);

        try {
            $notification = $this->messages->forEvents($channel, array_values($events->all()));
            $this->senders->for($channel->type)->send($spec, $notification, "iw-{$delivery->id}");
        } catch (DeliveryFailure $e) {
            $error = mb_substr(Redactor::redact($e->message(), $spec->secrets), 0, self::MAX_ERROR_LENGTH);
            $this->failure($delivery, $channel, $attempt, $error, $e->permanent, $e->retryAfter);

            return;
        } catch (\Throwable $e) {
            Log::warning('Alert delivery failed unexpectedly.', [
                'delivery_id' => $delivery->id,
                'channel_type' => $channel->type->value,
                'exception' => $e::class,
            ]);
            $this->failure($delivery, $channel, $attempt, 'Unexpected error: '.class_basename($e), false, null);

            return;
        }

        $now = CarbonImmutable::now();
        $this->finish($delivery, DeliveryStatus::Sent, null, $now);
        $this->channelHealth($channel, ['last_success_at' => $now]);
    }

    /**
     * Marks a delivery that the queue gave up on as failed, unless it already finished.
     */
    public function abandon(int $deliveryId, string $error): void
    {
        $error = mb_substr($error, 0, self::MAX_ERROR_LENGTH);
        $channelId = AlertDelivery::query()->whereKey($deliveryId)->value('notification_channel_id');
        $updated = AlertDelivery::query()
            ->whereKey($deliveryId)
            ->where('status', DeliveryStatus::Queued->value)
            ->update(['status' => DeliveryStatus::Failed->value, 'last_error' => $error]);

        if ($updated === 1 && is_numeric($channelId)) {
            NotificationChannel::query()->whereKey((int) $channelId)->toBase()
                ->update(['last_failure_at' => CarbonImmutable::now(), 'last_error' => $error]);
        }
    }

    /**
     * @throws RetryDelivery
     */
    private function failure(AlertDelivery $delivery, NotificationChannel $channel, int $attempt, string $error, bool $permanent, ?int $retryAfter): void
    {
        if ($permanent || $attempt >= self::tries()) {
            $this->finish($delivery, DeliveryStatus::Failed, $error);
            $this->channelHealth($channel, ['last_failure_at' => CarbonImmutable::now(), 'last_error' => $error]);

            return;
        }

        $this->finish($delivery, DeliveryStatus::Queued, $error);

        throw new RetryDelivery($retryAfter ?? self::backoff($attempt));
    }

    /**
     * Counts the attempt with a compare-and-set on the attempt counter that was read. Exactly one of several
     * jobs racing for the same queued delivery affects the row.
     */
    private function claim(AlertDelivery $delivery): bool
    {
        return AlertDelivery::query()
            ->whereKey($delivery->id)
            ->where('status', DeliveryStatus::Queued->value)
            ->where('attempts', $delivery->attempts)
            ->increment('attempts') === 1;
    }

    /**
     * Records the outcome. Guarded on the queued status so that a concurrent duplicate cannot overwrite a result.
     */
    private function finish(AlertDelivery $delivery, DeliveryStatus $status, ?string $error, ?CarbonImmutable $sentAt = null): void
    {
        AlertDelivery::query()
            ->whereKey($delivery->id)
            ->where('status', DeliveryStatus::Queued->value)
            ->update([
                'status' => $status->value,
                'last_error' => $error,
                'sent_at' => $sentAt,
            ]);
    }

    /**
     * Health columns are operational state: they do not bump the channel's updated_at and are not audited.
     *
     * @param  array<string, mixed>  $values
     */
    private function channelHealth(NotificationChannel $channel, array $values): void
    {
        NotificationChannel::query()->whereKey($channel->id)->toBase()->update($values);
    }

    public static function tries(): int
    {
        return max(1, config()->integer('alerting.send.tries', 5));
    }

    /**
     * Seconds to wait before each retry. Whole numbers given as strings (from the environment) are accepted.
     *
     * @return list<int>
     *
     * @throws \InvalidArgumentException When the setting is not a non-empty list of whole seconds.
     */
    public static function backoffs(): array
    {
        $configured = config('alerting.send.backoff_seconds', [10, 30, 120, 300]);
        $invalid = new \InvalidArgumentException('alerting.send.backoff_seconds must be a non-empty list of whole seconds (0 or more).');
        if (! is_array($configured) || $configured === []) {
            throw $invalid;
        }

        $values = [];
        foreach ($configured as $value) {
            if (is_string($value) && ctype_digit(trim($value))) {
                $value = (int) trim($value);
            }
            if (! is_int($value) || $value < 0) {
                throw $invalid;
            }
            $values[] = $value;
        }

        return $values;
    }

    private static function backoff(int $attempt): int
    {
        $values = self::backoffs();

        return $values[min(max(0, $attempt - 1), count($values) - 1)];
    }
}
