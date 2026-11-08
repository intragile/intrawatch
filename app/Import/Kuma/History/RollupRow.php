<?php

declare(strict_types=1);

namespace App\Import\Kuma\History;

/**
 * Builds `check_rollups` rows from the counters of one Kuma bucket. Latency columns are whole milliseconds, as
 * the schema defines; `latency_avg` is the rounded mean of the successful checks.
 */
final class RollupRow
{
    /** Columns that an upsert overwrites when the row already exists. */
    public const VALUES = [
        'total', 'up', 'down', 'degraded', 'pending', 'maintenance',
        'latency_min', 'latency_max', 'latency_avg', 'latency_sum', 'latency_count',
    ];

    /**
     * @param  float|null  $pingMean  Mean latency over the successful checks, null when none was measured.
     * @param  float|null  $pingSum  Total latency; defaults to the mean times the up count.
     * @param  int|null  $latencyCount  Checks that carry a latency; defaults to the up count.
     * @return array<string, int|string|null>
     */
    public static function make(
        int $monitorId,
        string $bucket,
        int $start,
        int $up,
        int $down,
        int $pending,
        int $maintenance,
        ?float $pingMean,
        ?float $pingMin,
        ?float $pingMax,
        ?float $pingSum = null,
        ?int $latencyCount = null,
    ): array {
        $up = max(0, $up);
        $down = max(0, $down);
        $pending = max(0, $pending);
        $maintenance = max(0, $maintenance);
        $count = max(0, $latencyCount ?? $up);
        $measured = $pingMean !== null && $count > 0;
        $sum = $pingSum ?? ($pingMean !== null ? $pingMean * $count : 0.0);

        return [
            'monitor_id' => $monitorId,
            'bucket' => $bucket,
            'bucket_start' => $start,
            'total' => $up + $down + $pending + $maintenance,
            'up' => $up,
            'down' => $down,
            'degraded' => 0,
            'pending' => $pending,
            'maintenance' => $maintenance,
            'latency_min' => $measured && $pingMin !== null ? self::whole($pingMin) : null,
            'latency_max' => $measured && $pingMax !== null ? self::whole($pingMax) : null,
            'latency_avg' => $measured ? self::whole($pingMean) : null,
            'latency_sum' => $measured ? self::whole($sum) : 0,
            'latency_count' => $measured ? $count : 0,
        ];
    }

    private static function whole(float $value): int
    {
        return max(0, (int) round($value));
    }
}
