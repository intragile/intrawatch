<?php

namespace App\Jobs;

use App\Alerting\DeliveryRunner;
use App\Alerting\RetryDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Sends one alert delivery to its notification channel. Carries the delivery id only, so neither the jobs
 * nor the failed_jobs table ever holds a secret or rendered text.
 *
 * Retries are decided by the delivery runner, which releases the job with the provider's Retry-After or the
 * backoff of the attempt. The backoff here only applies when the job itself throws (a database outage).
 */
class SendNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 30;

    /**
     * A send that outlives the timeout is not retried: every outbound transport ends far sooner (HTTP 10 s, SMTP
     * 10 s), so a timeout means a hung worker, and a retry could deliver the same alert twice.
     */
    public bool $failOnTimeout = true;

    public function __construct(public int $deliveryId)
    {
        $this->onQueue('notifications');
    }

    public function tries(): int
    {
        return DeliveryRunner::tries();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return DeliveryRunner::backoffs();
    }

    public function handle(DeliveryRunner $runner): void
    {
        try {
            $runner->run($this->deliveryId, $this->attempts());
        } catch (RetryDelivery $retry) {
            $this->release($retry->delay);
        }
    }

    /**
     * The queue gave up: a timeout (with failOnTimeout this fails the job on any attempt, not only the last) or
     * an exception on the last attempt. The delivery must not stay queued.
     */
    public function failed(Throwable $e): void
    {
        app(DeliveryRunner::class)->abandon($this->deliveryId, 'Unexpected error: '.class_basename($e));
    }
}
