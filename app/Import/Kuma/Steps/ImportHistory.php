<?php

declare(strict_types=1);

namespace App\Import\Kuma\Steps;

use App\Import\Kuma\History\HeartbeatAggregator;
use App\Import\Kuma\History\RollupWriter;
use App\Import\Kuma\History\StatsHistoryWriter;
use App\Import\Kuma\ImportContext;
use App\Import\Kuma\Mapping\Note;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Imports the measurement history of the monitors created by this run as hour and day rollups: Kuma 2.x
 * statistics directly, Kuma 1.23 heartbeats aggregated per monitor. Minute history and raw results are not
 * imported, rows beyond the rollup retention are dropped, and monitors that already existed keep their own history.
 */
final class ImportHistory implements ImportStep
{
    public function __construct(
        private readonly StatsHistoryWriter $stats,
        private readonly HeartbeatAggregator $heartbeats,
    ) {}

    public function run(ImportContext $ctx): void
    {
        if (! $ctx->options->history) {
            $ctx->report->historyNote(Note::of('history_disabled', 'History import was turned off; no measurements were imported.'));

            return;
        }

        $kind = $ctx->source->historyKind();
        if ($kind === 'none') {
            $ctx->report->historyNote(Note::of('history_unavailable', 'The source carries no measurement history.'));

            return;
        }

        $monitors = $ctx->mappings->createdInRun('monitor');
        if ($monitors === []) {
            $ctx->report->historyNote(Note::of('history_no_new_monitors', 'No monitor was created by this import, so no history was imported.'));

            return;
        }

        $now = CarbonImmutable::now()->getTimestamp();
        $hourCutoff = $now - config()->integer('monitoring.retention.hour_days', 90) * 86400;
        $dayCutoff = $now - config()->integer('monitoring.retention.day_days', 730) * 86400;

        try {
            $totals = $kind === 'stats'
                ? $this->stats->write($ctx, $monitors, $hourCutoff, $dayCutoff, $now)
                : $this->fromHeartbeats($ctx, $monitors, $hourCutoff, $dayCutoff, $now);
        } catch (Throwable $e) {
            Log::warning('Kuma history import failed', ['exception' => $e::class]);
            $ctx->report->historyNote(Note::of('history_failed', 'The history could not be imported completely; see the application log.'));

            return;
        }

        $ctx->report->addHistory($totals['monitors'], $totals['hour_rows'], $totals['day_rows']);

        if ($kind === 'stats') {
            $ctx->report->historyNote(Note::of(
                'history_pending_as_down',
                'Uptime Kuma 2.x statistics count pending checks as down, so the imported history does the same.',
            ));
            if ($ctx->workspace->timezone !== 'UTC') {
                $ctx->report->historyNote(Note::of(
                    'history_days_local_midnight',
                    'Uptime Kuma days are UTC days; they were stored at the workspace-local midnight of the same calendar date.',
                ));
            }
        }
    }

    /**
     * @param  array<int, int>  $monitors  Source monitor id => target monitor id.
     * @return array{monitors: int, hour_rows: int, day_rows: int}
     */
    private function fromHeartbeats(ImportContext $ctx, array $monitors, int $hourCutoff, int $dayCutoff, int $now): array
    {
        $writer = new RollupWriter(! $ctx->options->dryRun);
        $withRows = 0;
        $hourRows = 0;
        $dayRows = 0;

        foreach ($monitors as $kumaId => $targetId) {
            $rows = $this->heartbeats->aggregate(
                $targetId,
                $ctx->source->heartbeats($kumaId),
                $ctx->workspace->timezone,
                $hourCutoff,
                $dayCutoff,
                $now,
            );
            foreach ([...$rows['hours'], ...$rows['days']] as $row) {
                $writer->add($row);
            }
            $hourRows += count($rows['hours']);
            $dayRows += count($rows['days']);
            if ($rows['hours'] !== [] || $rows['days'] !== []) {
                $withRows++;
            }
        }
        $writer->flush();

        return ['monitors' => $withRows, 'hour_rows' => $hourRows, 'day_rows' => $dayRows];
    }
}
