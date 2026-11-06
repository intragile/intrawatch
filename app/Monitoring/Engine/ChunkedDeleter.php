<?php

namespace App\Monitoring\Engine;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * Deletes large row sets in short, id-bounded statements so that no single delete holds locks for long or
 * blows up the transaction log. Every statement is a plain `DELETE ... WHERE id BETWEEN ? AND ?`, which is
 * portable across SQLite, MySQL and PostgreSQL.
 */
final class ChunkedDeleter
{
    private readonly int $chunkSize;

    private readonly int $sleepMs;

    public function __construct(?int $chunkSize = null, ?int $sleepMs = null)
    {
        $this->chunkSize = max(1, $chunkSize ?? config()->integer('monitoring.retention.chunk_size', 5000));
        $this->sleepMs = max(0, $sleepMs ?? config()->integer('monitoring.retention.chunk_sleep_ms', 50));
    }

    /**
     * For rows that cluster at the low end of the id space (time-ordered inserts): find the id bounds of the
     * candidates once, then walk that range in windows of chunk-size ids. A window that deletes nothing skips
     * ahead to the next candidate, so sparse ranges stay cheap.
     *
     * @param  Closure(Builder): mixed  $filter  The exact condition a row must meet to be deleted.
     * @param  (Closure(Builder): mixed)|null  $candidates  An indexed, possibly wider condition used to find the
     *                                                      id bounds; defaults to $filter.
     */
    public function byIdRange(string $table, Closure $filter, ?Closure $candidates = null): int
    {
        $candidates ??= $filter;
        $bounds = DB::table($table)->where($candidates)->selectRaw('min(id) as low, max(id) as high')->first();
        if ($bounds === null || ! is_numeric($bounds->low) || ! is_numeric($bounds->high)) {
            return 0;
        }

        $cursor = (int) $bounds->low;
        $high = (int) $bounds->high;
        $deleted = 0;
        while ($cursor <= $high) {
            $last = min($high, $cursor + $this->chunkSize - 1);
            $count = DB::table($table)->whereBetween('id', [$cursor, $last])->where($filter)->delete();
            $deleted += $count;
            $cursor = $last + 1;

            if ($count === 0 && $cursor <= $high) {
                $next = DB::table($table)->where($candidates)->where('id', '>=', $cursor)->min('id');
                if (! is_numeric($next)) {
                    break;
                }
                $cursor = (int) $next;
            }
            if ($cursor <= $high) {
                $this->pause();
            }
        }

        return $deleted;
    }

    /**
     * For rows spread thinly across the id space (one monitor's rows): take the next chunk of matching ids in
     * order and delete exactly that id span.
     *
     * @param  Closure(Builder): mixed  $filter
     */
    public function byIdChunks(string $table, Closure $filter): int
    {
        $deleted = 0;
        while (true) {
            $ids = DB::table($table)->where($filter)->orderBy('id')->limit($this->chunkSize)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }

            $deleted += DB::table($table)->where($filter)->whereBetween('id', [$ids->first(), $ids->last()])->delete();
            if ($ids->count() < $this->chunkSize) {
                break;
            }
            $this->pause();
        }

        return $deleted;
    }

    /**
     * For tables without an integer id: delete the next chunk of matching keys until none are left.
     *
     * @param  Closure(Builder): mixed  $filter
     */
    public function byKey(string $table, string $key, Closure $filter): int
    {
        $deleted = 0;
        while (true) {
            $keys = DB::table($table)->where($filter)->orderBy($key)->limit($this->chunkSize)->pluck($key);
            if ($keys->isEmpty()) {
                break;
            }

            $deleted += DB::table($table)->whereIn($key, $keys->all())->where($filter)->delete();
            if ($keys->count() < $this->chunkSize) {
                break;
            }
            $this->pause();
        }

        return $deleted;
    }

    private function pause(): void
    {
        if ($this->sleepMs > 0) {
            Sleep::for($this->sleepMs)->milliseconds();
        }
    }
}
