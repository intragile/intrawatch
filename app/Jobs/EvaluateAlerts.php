<?php

namespace App\Jobs;

use App\Alerting\Engine\AlertEvaluator;
use App\Alerting\Engine\AlertSignals;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Evaluates the alert signals of one recorded batch (at most 200 monitors). Runs on the connection's default
 * queue.
 *
 * The payload holds scalars only. A retry, or a batch that was recorded twice, applies the same signals again;
 * the evaluator turns those replays into no-ops.
 */
class EvaluateAlerts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 60;

    /**
     * @param  list<array<string, mixed>>  $signals  See AlertSignals::toPayload().
     */
    public function __construct(public array $signals) {}

    public function handle(AlertEvaluator $evaluator): void
    {
        $evaluator->evaluate(AlertSignals::fromPayload($this->signals), CarbonImmutable::now());
    }
}
