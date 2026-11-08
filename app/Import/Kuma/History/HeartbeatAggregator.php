<?php

declare(strict_types=1);

namespace App\Import\Kuma\History;

use App\Models\CheckRollup;
use Carbon\CarbonImmutable;

/**
 * Aggregates the heartbeats of one monitor into hour rollups (UTC hours) and day rollups (workspace-local
 * days). Status 0 is down, 1 up, 2 pending, 3 maintenance; latency counts the up beats that carry a ping.
 * Beats older than the day cutoff are ignored, and hours older than the hour cutoff are not kept.
 */
final class HeartbeatAggregator
{
    /**
     * @param  iterable<array{status: int, ping: ?float, time: int}>  $beats  Oldest first.
     * @return array{hours: list<array<string, int|string|null>>, days: list<array<string, int|string|null>>}
     */
    public function aggregate(int $monitorId, iterable $beats, string $timezone, int $hourCutoff, int $dayCutoff, int $now): array
    {
        /** @var array<int, array{up: int, down: int, pending: int, maintenance: int, sum: float, count: int, min: ?float, max: ?float}> $hours */
        $hours = [];
        /** @var array<int, array{up: int, down: int, pending: int, maintenance: int, sum: float, count: int, min: ?float, max: ?float}> $days */
        $days = [];
        $windowStart = 0;
        $windowEnd = 0;

        foreach ($beats as $beat) {
            $time = $beat['time'];
            if ($time < $dayCutoff || $time > $now || $beat['status'] < 0 || $beat['status'] > 3) {
                continue;
            }
            if ($time < $windowStart || $time >= $windowEnd) {
                $local = CarbonImmutable::createFromTimestamp($time, $timezone)->startOfDay();
                $windowStart = $local->getTimestamp();
                $windowEnd = $local->addDay()->startOfDay()->getTimestamp();
            }
            $hour = $time - $time % 3600;
            if ($hour >= $hourCutoff) {
                $hours[$hour] ??= self::empty();
                self::tally($hours[$hour], $beat);
            }
            if ($windowStart >= $dayCutoff) {
                $days[$windowStart] ??= self::empty();
                self::tally($days[$windowStart], $beat);
            }
        }

        return [
            'hours' => self::rows($monitorId, CheckRollup::BUCKET_HOUR, $hours),
            'days' => self::rows($monitorId, CheckRollup::BUCKET_DAY, $days),
        ];
    }

    /**
     * @return array{up: int, down: int, pending: int, maintenance: int, sum: float, count: int, min: ?float, max: ?float}
     */
    private static function empty(): array
    {
        return ['up' => 0, 'down' => 0, 'pending' => 0, 'maintenance' => 0, 'sum' => 0.0, 'count' => 0, 'min' => null, 'max' => null];
    }

    /**
     * @param  array{up: int, down: int, pending: int, maintenance: int, sum: float, count: int, min: ?float, max: ?float}  $bucket
     * @param  array{status: int, ping: ?float, time: int}  $beat
     */
    private static function tally(array &$bucket, array $beat): void
    {
        switch ($beat['status']) {
            case 1:
                $bucket['up']++;
                if ($beat['ping'] !== null) {
                    $bucket['sum'] += $beat['ping'];
                    $bucket['count']++;
                    $bucket['min'] = $bucket['min'] === null ? $beat['ping'] : min($bucket['min'], $beat['ping']);
                    $bucket['max'] = $bucket['max'] === null ? $beat['ping'] : max($bucket['max'], $beat['ping']);
                }
                break;
            case 0:
                $bucket['down']++;
                break;
            case 2:
                $bucket['pending']++;
                break;
            default:
                $bucket['maintenance']++;
        }
    }

    /**
     * @param  array<int, array{up: int, down: int, pending: int, maintenance: int, sum: float, count: int, min: ?float, max: ?float}>  $buckets
     * @return list<array<string, int|string|null>>
     */
    private static function rows(int $monitorId, string $bucket, array $buckets): array
    {
        $rows = [];
        foreach ($buckets as $start => $t) {
            $rows[] = RollupRow::make(
                $monitorId, $bucket, $start, $t['up'], $t['down'], $t['pending'], $t['maintenance'],
                $t['count'] > 0 ? $t['sum'] / $t['count'] : null, $t['min'], $t['max'], $t['sum'], $t['count'],
            );
        }

        return $rows;
    }
}
