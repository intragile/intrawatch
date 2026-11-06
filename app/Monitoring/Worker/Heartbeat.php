<?php

namespace App\Monitoring\Worker;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes the worker's liveness and load in `check_workers`, one row per shard.
 *
 * Checks are counted in one-second buckets over a sliding minute, which keeps the bookkeeping constant in
 * size however many checks run.
 */
final class Heartbeat
{
    private const WINDOW_SECONDS = 60;

    private readonly CarbonImmutable $startedAt;

    /** @var array<int, array{second: int, checks: int, maxDrift: int}> */
    private array $buckets = [];

    public function __construct(
        private readonly Clock $clock,
        private readonly int $shard,
        private readonly int $shards,
    ) {
        $this->startedAt = $clock->now();
    }

    public function recordCheck(CarbonImmutable $startedAt, int $driftMs): void
    {
        $second = $startedAt->getTimestamp();
        $slot = $second % self::WINDOW_SECONDS;
        $bucket = $this->buckets[$slot] ?? null;
        if ($bucket === null || $bucket['second'] !== $second) {
            $bucket = ['second' => $second, 'checks' => 0, 'maxDrift' => 0];
        }
        $bucket['checks']++;
        $bucket['maxDrift'] = max($bucket['maxDrift'], $driftMs);
        $this->buckets[$slot] = $bucket;
    }

    /**
     * @return array{checks: int, maxDriftMs: int}
     */
    public function lastMinute(): array
    {
        $since = $this->clock->now()->getTimestamp() - self::WINDOW_SECONDS;
        $checks = 0;
        $maxDrift = 0;
        foreach ($this->buckets as $bucket) {
            if ($bucket['second'] > $since) {
                $checks += $bucket['checks'];
                $maxDrift = max($maxDrift, $bucket['maxDrift']);
            }
        }

        return ['checks' => $checks, 'maxDriftMs' => $maxDrift];
    }

    /**
     * Writes the row. A failure is logged and returned, never thrown: a missed heartbeat must not stop checks.
     */
    public function beat(int $monitors, int $buffered): ?Throwable
    {
        $stats = $this->lastMinute();

        try {
            DB::table('check_workers')->upsert([[
                'shard' => $this->shard,
                'shards' => $this->shards,
                'hostname' => mb_substr(gethostname() ?: 'unknown', 0, 255),
                'pid' => getmypid() ?: 0,
                'started_at' => $this->startedAt,
                'last_seen_at' => $this->clock->now(),
                'monitors' => $monitors,
                'checks_last_minute' => $stats['checks'],
                'max_drift_ms_last_minute' => max(0, $stats['maxDriftMs']),
                'buffered' => $buffered,
            ]], ['shard'], [
                'shards', 'hostname', 'pid', 'started_at', 'last_seen_at', 'monitors', 'checks_last_minute',
                'max_drift_ms_last_minute', 'buffered',
            ]);
        } catch (Throwable $e) {
            Log::warning('Writing the worker heartbeat failed.', [
                'shard' => $this->shard,
                'exception' => $e::class,
            ]);

            return $e;
        }

        return null;
    }
}
