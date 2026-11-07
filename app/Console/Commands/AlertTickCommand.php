<?php

namespace App\Console\Commands;

use App\Alerting\Engine\AlertEvaluator;
use App\Alerting\Engine\Signals\TickSignal;
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

    public function handle(AlertEvaluator $evaluator): int
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

        return self::SUCCESS;
    }
}
