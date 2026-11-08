<?php

declare(strict_types=1);

namespace App\Import\Kuma\History;

use App\Import\Kuma\ImportContext;
use App\Models\CheckRollup;
use App\Monitoring\Engine\RollupBuilder;
use Carbon\CarbonImmutable;

/**
 * Writes Uptime Kuma 2.x statistics as rollups: hourly rows become hour rollups; daily rows become day rollups
 * for the days that no imported hour covers. Days an imported hour reaches into are rebuilt from the hour rows
 * by the rollup builder, so they match what the engine itself would have produced.
 *
 * The source streams the rows ordered by monitor and time, so memory does not grow with the history. Rows that
 * land on the same bucket collapse into one (the last wins) and are counted once.
 */
final class StatsHistoryWriter
{
    private const REBUILD_CHUNK = 1000;

    public function __construct(private readonly RollupBuilder $builder) {}

    /**
     * @param  array<int, int>  $monitors  Source monitor id => target monitor id (placeholders in dry-run).
     * @return array{monitors: int, hour_rows: int, day_rows: int}
     */
    public function write(ImportContext $ctx, array $monitors, int $hourCutoff, int $dayCutoff, int $now): array
    {
        $timezone = $ctx->workspace->timezone;
        $writer = new RollupWriter(! $ctx->options->dryRun);

        /** @var array<int, int> $firstFull source monitor id => first local day fully covered by imported hours */
        $firstFull = [];
        /** @var array<int, int> $lastDay source monitor id => start of the last counted rebuilt day */
        $lastDay = [];
        /** @var array<int, true> $touched */
        $touched = [];
        $hourRows = 0;
        $previousHour = null;
        $previousDay = null;
        $dayRows = 0;
        $windowStart = 0;
        $windowEnd = 0;

        foreach ($ctx->source->hourStats() as $row) {
            $kumaId = $row['monitor_id'];
            $start = $row['timestamp'] - $row['timestamp'] % 3600;
            if (! isset($monitors[$kumaId]) || $start < $hourCutoff || $start > $now) {
                continue;
            }
            if ($start < $windowStart || $start >= $windowEnd) {
                $local = CarbonImmutable::createFromTimestamp($start, $timezone)->startOfDay();
                $windowStart = $local->getTimestamp();
                $windowEnd = $local->addDay()->startOfDay()->getTimestamp();
            }
            if (! isset($firstFull[$kumaId])) {
                $firstFull[$kumaId] = $windowStart === $start ? $start : $windowEnd;
            }
            if ($windowStart >= $firstFull[$kumaId] && ($lastDay[$kumaId] ?? null) !== $windowStart) {
                $lastDay[$kumaId] = $windowStart;
                $dayRows++;
            }

            $writer->add(self::row($monitors[$kumaId], CheckRollup::BUCKET_HOUR, $start, $row));
            $touched[$kumaId] = true;
            if ($previousHour !== [$kumaId, $start]) {
                $hourRows++;
            }
            $previousHour = [$kumaId, $start];
        }
        $writer->flush();

        foreach ($ctx->source->dayStats() as $row) {
            $kumaId = $row['monitor_id'];
            if (! isset($monitors[$kumaId])) {
                continue;
            }
            $date = CarbonImmutable::createFromTimestampUTC($row['timestamp'])->format('Y-m-d');
            $start = CarbonImmutable::parse($date.' 00:00:00', $timezone)->getTimestamp();
            if ($start < $dayCutoff || $start >= ($firstFull[$kumaId] ?? PHP_INT_MAX) || $start > $now) {
                continue;
            }

            $writer->add(self::row($monitors[$kumaId], CheckRollup::BUCKET_DAY, $start, $row));
            $touched[$kumaId] = true;
            if ($previousDay !== [$kumaId, $start]) {
                $dayRows++;
            }
            $previousDay = [$kumaId, $start];
        }
        $writer->flush();

        if (! $ctx->options->dryRun) {
            $this->rebuild($ctx, $monitors, $firstFull);
        }

        return ['monitors' => count($touched), 'hour_rows' => $hourRows, 'day_rows' => $dayRows];
    }

    /**
     * Monitors that share the first fully covered day are rebuilt together, in chunks.
     *
     * @param  array<int, int>  $monitors
     * @param  array<int, int>  $firstFull
     */
    private function rebuild(ImportContext $ctx, array $monitors, array $firstFull): void
    {
        /** @var array<int, list<int>> $byDay */
        $byDay = [];
        foreach ($firstFull as $kumaId => $day) {
            $byDay[$day][] = $monitors[$kumaId];
        }
        foreach ($byDay as $day => $ids) {
            foreach (array_chunk($ids, self::REBUILD_CHUNK) as $chunk) {
                $this->builder->rebuildDaysFor($ctx->workspace, $chunk, $day);
            }
        }
    }

    /**
     * @param  array{monitor_id: int, timestamp: int, up: int, down: int, maintenance: int, ping: ?float, ping_min: ?float, ping_max: ?float}  $stat
     * @return array<string, int|string|null>
     */
    private static function row(int $monitorId, string $bucket, int $start, array $stat): array
    {
        return RollupRow::make(
            $monitorId, $bucket, $start, $stat['up'], $stat['down'], 0, $stat['maintenance'],
            $stat['ping'], $stat['ping_min'], $stat['ping_max'],
        );
    }
}
