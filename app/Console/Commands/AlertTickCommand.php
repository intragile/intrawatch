<?php

namespace App\Console\Commands;

use App\Alerting\DeliveryRunner;
use App\Alerting\Engine\AlertEvaluator;
use App\Alerting\Engine\Signals\TickSignal;
use App\Enums\DeliveryStatus;
use App\Jobs\SendNotification;
use App\Models\AlertDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('intrawatch:alerts:tick')]
#[Description('Send due degraded alerts, reminders, flap ends and alerts held back by a group outage')]
class AlertTickCommand extends Command
{
    private const PAGE = 500;

    private const MAX_PAGES = 10;

    /**
     * A delivery that stays queued this long has lost its job: the queue's retry_after, the longest backoff and a
     * capped Retry-After all fit well inside it.
     */
    private const ORPHAN_AFTER_SECONDS = 900;

    private const ORPHAN_BATCH = 200;

    public function handle(AlertEvaluator $evaluator, DeliveryRunner $runner): int
    {
        $now = CarbonImmutable::now();
        $tick = new TickSignal($now);

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $ids = DB::table('alert_states')
                ->whereNotNull('due_at')
                ->where('due_at', '<=', $now)
                ->orderBy('due_at')
                ->orderBy('monitor_id')
                ->limit(self::PAGE)
                ->pluck('monitor_id')
                ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
                ->all();

            if ($ids !== []) {
                $evaluator->evaluate(array_fill_keys($ids, [$tick]), $now);
            }
            // Evaluated states move their due time past now, so the next page holds the ones not reached yet.
            if (count($ids) < self::PAGE) {
                break;
            }
        }

        $this->recoverOrphanedDeliveries($runner, $now);

        return self::SUCCESS;
    }

    /**
     * Queues a new job for a delivery that has been waiting without progress, or fails it once its attempts are
     * used up. The delivery runner ignores a job for a finished delivery and lets only one job claim an attempt,
     * so a job that was merely slow cannot send twice.
     */
    private function recoverOrphanedDeliveries(DeliveryRunner $runner, CarbonImmutable $now): void
    {
        $cutoff = $now->subSeconds(self::ORPHAN_AFTER_SECONDS);
        $stale = AlertDelivery::query()
            ->where('status', DeliveryStatus::Queued->value)
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit(self::ORPHAN_BATCH)
            ->get(['id', 'attempts', 'updated_at']);

        foreach ($stale as $delivery) {
            if ($delivery->attempts >= DeliveryRunner::tries()) {
                $runner->abandon($delivery->id, 'Delivery lost: no worker completed it');

                continue;
            }

            // Moving the timestamp first lets only one ticking process re-queue a delivery, and spaces repeats out.
            $moved = AlertDelivery::query()
                ->whereKey($delivery->id)
                ->where('status', DeliveryStatus::Queued->value)
                ->where('updated_at', $delivery->updated_at)
                ->update(['updated_at' => $now]);
            if ($moved === 1) {
                SendNotification::dispatch($delivery->id);
            }
        }
    }
}
