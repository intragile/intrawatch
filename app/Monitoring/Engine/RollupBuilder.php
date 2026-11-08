<?php

namespace App\Monitoring\Engine;

use App\Models\CheckRollup;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates raw check results into minute rollups, minute rollups into hour rollups, and both into day rollups
 * per workspace-local day. Every level is recomputed from the level below, and only rows whose values changed
 * are written, so a run is idempotent and cheap when nothing changed.
 *
 * Two cursors decide what is recomputed:
 * - a time watermark: every run re-aggregates the minutes in [watermark - LOOKBACK, now - 1 min);
 * - a result id watermark: results inserted since the last run whose minute lies before that window (outcomes a
 *   worker buffered during a database outage and flushed later) mark their minutes for recomputation.
 *
 * Load profile: a run touches about six minutes of raw rows (one minute per query), the minutes of at most
 * `monitoring.rollups.late_id_batch` newly inserted results, the hours those minutes belong to, and the local
 * days of the workspaces involved. All aggregation happens in SQL; PHP only sees one aggregated row per monitor
 * and bucket.
 */
final class RollupBuilder
{
    public const WATERMARK_KEY = 'rollup:watermark';

    public const LAST_ID_KEY = 'rollup:last_id';

    /** Recent (time, max result id) observations, from which the safe point of the id watermark is taken. */
    public const ID_MARKS_KEY = 'rollup:id_marks';

    /**
     * Seconds an observed max result id must age before the id watermark may pass it. On MySQL and PostgreSQL
     * ids are allocated at insert time but become visible at commit, so a lower id can appear after a higher one;
     * no recorder transaction lives this long.
     */
    public const SAFE_LAG = 120;

    private const MAX_ID_MARKS = 20;

    /** Seconds before the watermark that every run re-aggregates, to absorb results committed slightly late. */
    public const LOOKBACK = 300;

    /** Most seconds of minute buckets a single run aggregates; a longer backlog is worked off over several runs. */
    public const MAX_CATCH_UP = 3600;

    /** Seconds a rebuild waits for a running day build of its workspace to finish before it gives up. */
    private const REBUILD_WAIT = 600;

    /** The longest possible local day (a day that ends daylight saving time). */
    private const LONGEST_DAY = 90000;

    private const UPSERT_CHUNK = 500;

    private const COUNTERS = ['total', 'up', 'down', 'degraded', 'pending', 'maintenance'];

    private const VALUES = [...self::COUNTERS, 'latency_min', 'latency_max', 'latency_avg', 'latency_sum', 'latency_count'];

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * Aggregate the minute buckets in [watermark - LOOKBACK, now - 1 min) and the minutes of late results, the
     * hours they belong to and the workspace-local days those hours touch, then advance both watermarks. Days of a
     * workspace whose day rollups are being rebuilt are skipped; minutes and hours never wait.
     */
    public function run(CarbonImmutable $now): void
    {
        $this->build($now);
    }

    /**
     * The lock a workspace's day rebuild holds, and that a rollup run takes while it builds that workspace's days.
     */
    public static function rebuildLockKey(int $workspaceId): string
    {
        return 'rollup:rebuild:'.$workspaceId;
    }

    /**
     * Rebuild the day rollups of a workspace for its current timezone from the rollups still retained. Days whose
     * minute rows are retained are exact; older days are summed from hour rows, which is exact for whole-hour
     * offsets and approximate (shifted by the sub-hour part of the offset) for offsets such as +05:30.
     *
     * Each local day is replaced in its own transaction (see buildDay), so an interrupted rebuild never leaves
     * overlapping day rows. Day rows older than the retained hour rows keep the boundaries of the timezone they
     * were built in; the one old row that would overlap the first rebuilt day is dropped.
     */
    public function rebuildDays(Workspace $workspace): void
    {
        $this->locked($workspace, null, null);
    }

    /**
     * Rebuild the day rollups of the given monitors of a workspace only, from their retained hour rows, leaving
     * the day rows of every other monitor untouched. Days that start before `$notBefore` (epoch seconds) are
     * skipped, which keeps a partially covered first day from replacing a more complete row.
     *
     * @param  list<int>  $monitorIds
     */
    public function rebuildDaysFor(Workspace $workspace, array $monitorIds, ?int $notBefore = null): void
    {
        if ($monitorIds === []) {
            return;
        }
        $this->locked($workspace, $monitorIds, $notBefore);
    }

    /**
     * @param  list<int>|null  $monitorIds
     */
    private function locked(Workspace $workspace, ?array $monitorIds, ?int $notBefore): void
    {
        Cache::lock(self::rebuildLockKey($workspace->id), self::REBUILD_WAIT + 360)
            ->block(self::REBUILD_WAIT, function () use ($workspace, $monitorIds, $notBefore): void {
                $this->rebuild($workspace, $monitorIds, $notBefore);
            });
    }

    private function build(CarbonImmutable $now): void
    {
        // Minute buckets that start before now - 60 s.
        $end = intdiv($now->getTimestamp() - 60 + 59, 60) * 60;
        $maxId = self::nullableInt(DB::table('check_results')->max('id')) ?? 0;

        $oldestRaw = self::nullableInt(DB::table('check_results')->min('bucket_minute'));
        if ($oldestRaw === null) {
            $this->cache->forever(self::WATERMARK_KEY, $end);
            $this->safePoint($now->getTimestamp(), $maxId);

            return;
        }

        $start = max($this->resumePoint($oldestRaw), $oldestRaw);
        $end = min($end, $start + self::MAX_CATCH_UP);

        /** @var array<int, array<int, true>> $touched hour => monitor ids */
        $touched = [];
        /** @var array<int, true> $fullHours hours that are rebuilt for every monitor */
        $fullHours = [];

        for ($minute = $start; $minute < $end; $minute += 60) {
            $hour = $minute - $minute % 3600;
            foreach ($this->buildMinute($minute) as $monitorId) {
                $touched[$hour][$monitorId] = true;
                $fullHours[$hour] = true;
            }
        }

        $lastId = $this->lateMinutes($now, $start, $maxId, function (int $minute, array $monitorIds) use (&$touched): void {
            $hour = $minute - $minute % 3600;
            foreach ($this->buildMinute($minute, $monitorIds) as $monitorId) {
                $touched[$hour][$monitorId] = true;
            }
        });

        ksort($touched);
        foreach ($touched as $hour => $monitorIds) {
            $this->buildHour($hour, isset($fullHours[$hour]) ? null : array_keys($monitorIds));
        }

        $this->buildTouchedDays($now, $touched);

        if ($start < $end) {
            $this->cache->forever(self::WATERMARK_KEY, $end);
        }
        if ($lastId !== null) {
            $this->cache->forever(self::LAST_ID_KEY, $lastId);
        }
    }

    /**
     * Feed the minutes of results inserted since the last run that lie before the regular window to $build. The
     * id watermark only advances to a safe point, the max result id observed at least SAFE_LAG seconds ago, and
     * by at most `monitoring.rollups.late_id_batch` ids per run. Minutes old enough to be partly pruned are
     * skipped. Without a safe point (cold start, cleared cache) nothing is scanned; a missing id watermark starts
     * at the first safe point.
     *
     * @param  Closure(int, list<int>): void  $build  Receives a minute and the monitors to recompute in it.
     * @return int|null The new id watermark, or null while it is still unknown.
     */
    private function lateMinutes(CarbonImmutable $now, int $windowStart, int $maxId, Closure $build): ?int
    {
        $lastId = $this->cache->get(self::LAST_ID_KEY);
        $lastId = is_numeric($lastId) ? (int) $lastId : null;
        $safeId = $this->safePoint($now->getTimestamp(), $maxId);
        if ($safeId === null) {
            return $lastId;
        }
        if ($lastId === null) {
            return $safeId;
        }

        $upTo = min($safeId, $lastId + max(1, config()->integer('monitoring.rollups.late_id_batch', 50000)));
        if ($upTo <= $lastId) {
            return $lastId;
        }

        $rawCutoff = $now->getTimestamp() - config()->integer('monitoring.retention.raw_hours', 24) * 3600;
        $pairs = DB::table('check_results')
            ->where('id', '>', $lastId)
            ->where('id', '<=', $upTo)
            ->where('bucket_minute', '<', $windowStart)
            ->where('bucket_minute', '>', $rawCutoff - $rawCutoff % 60)
            ->distinct()
            ->get(['monitor_id', 'bucket_minute']);

        $minutes = [];
        foreach ($pairs as $pair) {
            $minutes[self::int($pair->bucket_minute)][self::int($pair->monitor_id)] = true;
        }
        ksort($minutes);
        foreach ($minutes as $minute => $monitorIds) {
            $build($minute, array_keys($monitorIds));
        }

        return $upTo;
    }

    /**
     * Record this run's max result id and return the newest one observed at least SAFE_LAG seconds ago.
     */
    private function safePoint(int $now, int $maxId): ?int
    {
        $marks = [];
        $stored = $this->cache->get(self::ID_MARKS_KEY);
        foreach (is_array($stored) ? $stored : [] as $mark) {
            if (is_array($mark) && is_int($mark[0] ?? null) && is_int($mark[1] ?? null) && $mark[0] <= $now) {
                $marks[] = [$mark[0], $mark[1]];
            }
        }
        $marks[] = [$now, $maxId];
        usort($marks, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $safe = null;
        $keepFrom = 0;
        foreach ($marks as $index => [$time, $id]) {
            if ($time <= $now - self::SAFE_LAG) {
                $safe = $id;
                $keepFrom = $index;
            }
        }
        $this->cache->forever(self::ID_MARKS_KEY, array_slice(array_slice($marks, $keepFrom), -self::MAX_ID_MARKS));

        return $safe;
    }

    /**
     * @param  array<int, array<int, true>>  $touched  hour => monitor ids
     */
    private function buildTouchedDays(CarbonImmutable $now, array $touched): void
    {
        $monitorIds = [];
        foreach ($touched as $ids) {
            $monitorIds += $ids;
        }
        $workspaceOf = $this->workspacesOfMonitors(array_keys($monitorIds));

        /** @var array<int, array<int, true>> $workspaceHours */
        $workspaceHours = [];
        foreach ($touched as $hour => $ids) {
            foreach (array_keys($ids) as $monitorId) {
                if (isset($workspaceOf[$monitorId])) {
                    $workspaceHours[$workspaceOf[$monitorId]][$hour] = true;
                }
            }
        }

        $minuteCutoff = $now->getTimestamp() - $this->minuteRetentionSeconds();
        ksort($workspaceHours);
        foreach ($workspaceHours as $workspaceId => $hours) {
            // A workspace whose days are being rebuilt is skipped; the rebuild covers these days. The timezone is
            // read under the lock, so days are never built for a timezone a finished rebuild replaced.
            $lock = Cache::lock(self::rebuildLockKey($workspaceId), 600);
            if (! $lock->get()) {
                continue;
            }

            try {
                $timezone = DB::table('workspaces')->where('id', $workspaceId)->value('timezone');
                if (! is_string($timezone)) {
                    continue;
                }
                $days = [];
                foreach (array_keys($hours) as $hour) {
                    foreach ($this->localDays($timezone, $hour, $hour + 3600) as [$dayStart, $dayEnd]) {
                        $days[$dayStart] = $dayEnd;
                    }
                }
                ksort($days);
                foreach ($days as $dayStart => $dayEnd) {
                    $this->buildDay($workspaceId, $timezone, $dayStart, $dayEnd, $dayStart >= $minuteCutoff);
                }
            } finally {
                $lock->release();
            }
        }
    }

    /**
     * @param  list<int>|null  $monitorIds
     */
    private function rebuild(Workspace $workspace, ?array $monitorIds, ?int $notBefore): void
    {
        $now = CarbonImmutable::now();
        $timezone = $workspace->timezone;

        $oldestHour = self::nullableInt($this->forWorkspace(DB::table('check_rollups'), $workspace->id, $monitorIds)
            ->where('check_rollups.bucket', CheckRollup::BUCKET_HOUR)
            ->min('check_rollups.bucket_start'));
        if ($oldestHour === null) {
            return;
        }

        // The first day is only complete if no hour row of it can have been pruned already.
        $hourCutoff = $now->getTimestamp() - config()->integer('monitoring.retention.hour_days', 90) * 86400;
        $firstDay = $this->dayStart($timezone, $oldestHour);
        if ($firstDay < $hourCutoff) {
            $firstDay = $this->nextDayStart($timezone, $firstDay);
        }
        if ($notBefore !== null && $firstDay < $notBefore) {
            $firstDay = $notBefore;
        }

        $minuteCutoff = $now->getTimestamp() - $this->minuteRetentionSeconds();
        foreach ($this->localDays($timezone, $firstDay, $now->getTimestamp()) as [$dayStart, $dayEnd]) {
            $this->buildDay($workspace->id, $timezone, $dayStart, $dayEnd, $dayStart >= $minuteCutoff, $monitorIds);
        }
    }

    /**
     * Delete day rows of another timezone that overlap a local day: rows that start inside it, and rows that
     * start within the longest possible day before it without being a local midnight of the current timezone.
     * Rows on a local midnight of the current timezone are current and stay.
     *
     * @param  list<int>|null  $monitorIds  Null covers every monitor of the workspace.
     */
    private function dropOverlappingDays(int $workspaceId, string $timezone, int $dayStart, int $dayEnd, ?array $monitorIds = null): void
    {
        $starts = $this->dayRows($workspaceId, $monitorIds)
            ->where('bucket_start', '>', $dayStart - self::LONGEST_DAY)
            ->where('bucket_start', '<', $dayEnd)
            ->where('bucket_start', '<>', $dayStart)
            ->distinct()
            ->pluck('bucket_start');

        $stale = [];
        foreach ($starts as $start) {
            $start = self::int($start);
            if ($start > $dayStart || $this->dayStart($timezone, $start) !== $start) {
                $stale[] = $start;
            }
        }
        if ($stale !== []) {
            $this->dayRows($workspaceId, $monitorIds)->whereIn('bucket_start', $stale)->delete();
        }
    }

    /**
     * Day rollups of the monitors of one workspace, or of the given monitors only.
     *
     * @param  list<int>|null  $monitorIds
     */
    private function dayRows(int $workspaceId, ?array $monitorIds = null): Builder
    {
        return DB::table('check_rollups')
            ->where('bucket', CheckRollup::BUCKET_DAY)
            ->when($monitorIds !== null, fn (Builder $q) => $q->whereIn('monitor_id', $monitorIds ?? []))
            ->whereIn('monitor_id', fn (Builder $q) => $q->select('id')->from('monitors')->where('workspace_id', $workspaceId));
    }

    /**
     * Where the next run starts: shortly before the stored watermark, or, when the watermark was lost, shortly
     * before the newest minute rollup, or at the oldest raw result when no minute rollup exists.
     */
    private function resumePoint(int $oldestRaw): int
    {
        $watermark = $this->cache->get(self::WATERMARK_KEY);
        if (is_numeric($watermark)) {
            return (int) $watermark - self::LOOKBACK;
        }

        $newest = self::nullableInt(DB::table('check_rollups')->where('bucket', CheckRollup::BUCKET_MINUTE)->max('bucket_start'));

        return $newest !== null ? $newest - self::LOOKBACK : $oldestRaw;
    }

    /**
     * Recompute one minute, for every monitor or for the given ones. Results of monitors that no longer exist
     * are ignored, so a purge running concurrently cannot leave orphan rollups behind.
     *
     * @param  list<int>|null  $monitorIds
     * @return list<int> The monitors that have results in this minute.
     */
    private function buildMinute(int $minute, ?array $monitorIds = null): array
    {
        $found = [];
        foreach ($monitorIds === null ? [null] : array_chunk($monitorIds, self::UPSERT_CHUNK) as $chunk) {
            $rows = DB::table('check_results')
                ->join('monitors', 'monitors.id', '=', 'check_results.monitor_id')
                ->where('check_results.bucket_minute', $minute)
                ->when($chunk !== null, fn (Builder $q) => $q->whereIn('check_results.monitor_id', $chunk ?? []))
                ->groupBy('check_results.monitor_id')
                ->select('check_results.monitor_id')
                ->selectRaw('count(*) as total')
                ->selectRaw("sum(case when check_results.status = 'up' then 1 else 0 end) as up")
                ->selectRaw("sum(case when check_results.status = 'down' then 1 else 0 end) as down")
                ->selectRaw("sum(case when check_results.status = 'degraded' then 1 else 0 end) as degraded")
                ->selectRaw("sum(case when check_results.status = 'pending' then 1 else 0 end) as pending")
                ->selectRaw("sum(case when check_results.status = 'maintenance' then 1 else 0 end) as maintenance")
                ->selectRaw('min(check_results.latency_ms) as latency_min')
                ->selectRaw('max(check_results.latency_ms) as latency_max')
                ->selectRaw('coalesce(sum(check_results.latency_ms), 0) as latency_sum')
                ->selectRaw('count(check_results.latency_ms) as latency_count')
                ->get();

            $records = [];
            foreach ($rows as $row) {
                $monitorId = self::int($row->monitor_id);
                $records[] = $this->record($monitorId, CheckRollup::BUCKET_MINUTE, $minute, (array) $row);
                $found[] = $monitorId;
            }
            $this->upsertChanged($records);
        }

        return $found;
    }

    /**
     * @param  list<int>|null  $monitorIds  Null recomputes the hour for every monitor.
     */
    private function buildHour(int $hour, ?array $monitorIds = null): void
    {
        foreach ($monitorIds === null ? [null] : array_chunk($monitorIds, self::UPSERT_CHUNK) as $chunk) {
            $rows = $this->summed(DB::table('check_rollups'))
                ->join('monitors', 'monitors.id', '=', 'check_rollups.monitor_id')
                ->where('check_rollups.bucket', CheckRollup::BUCKET_MINUTE)
                ->where('check_rollups.bucket_start', '>=', $hour)
                ->where('check_rollups.bucket_start', '<', $hour + 3600)
                ->when($chunk !== null, fn (Builder $q) => $q->whereIn('check_rollups.monitor_id', $chunk ?? []))
                ->groupBy('check_rollups.monitor_id')
                ->addSelect('check_rollups.monitor_id')
                ->get();

            $records = [];
            foreach ($rows as $row) {
                $records[] = $this->record(self::int($row->monitor_id), CheckRollup::BUCKET_HOUR, $hour, (array) $row);
            }
            $this->upsertChanged($records);
        }
    }

    /**
     * Aggregate one local day of a workspace. Whole hours inside the day come from hour rows; when the day does
     * not start on a whole hour (offsets such as +05:30), the partial hours at either end come from minute rows.
     * Because hour rows are exact sums of minute rows, this equals summing the day's minute rows while reading
     * at most 25 hour rows and 60 minute rows per monitor. Days of 23 and 25 hours are handled the same way.
     *
     * Without minute rows ($exact false), the day is summed from the hour rows that start inside it, which is
     * approximate for sub-hour offsets.
     *
     * The day row is written and day rows of another timezone that overlap it are deleted in one transaction, so
     * overlapping (double-counted) days are never visible, whether this runs from a rollup or from a rebuild.
     *
     * @param  list<int>|null  $monitorIds  Null aggregates every monitor of the workspace.
     */
    private function buildDay(int $workspaceId, string $timezone, int $dayStart, int $dayEnd, bool $exact, ?array $monitorIds = null): void
    {
        $hourFrom = $exact ? intdiv($dayStart + 3599, 3600) * 3600 : $dayStart;
        $hourTo = $exact ? $dayEnd - $dayEnd % 3600 : $dayEnd;

        $parts = [
            $this->summed($this->forWorkspace(DB::table('check_rollups'), $workspaceId, $monitorIds))
                ->where('check_rollups.bucket', CheckRollup::BUCKET_HOUR)
                ->where('check_rollups.bucket_start', '>=', $hourFrom)
                ->where('check_rollups.bucket_start', '<', $hourTo)
                ->groupBy('check_rollups.monitor_id')
                ->addSelect('check_rollups.monitor_id')
                ->get(),
        ];

        if ($exact && ($hourFrom > $dayStart || $hourTo < $dayEnd)) {
            $parts[] = $this->summed($this->forWorkspace(DB::table('check_rollups'), $workspaceId, $monitorIds))
                ->where('check_rollups.bucket', CheckRollup::BUCKET_MINUTE)
                ->where(fn (Builder $q) => $q
                    ->where(fn (Builder $edge) => $edge
                        ->where('check_rollups.bucket_start', '>=', $dayStart)
                        ->where('check_rollups.bucket_start', '<', $hourFrom))
                    ->orWhere(fn (Builder $edge) => $edge
                        ->where('check_rollups.bucket_start', '>=', $hourTo)
                        ->where('check_rollups.bucket_start', '<', $dayEnd)))
                ->groupBy('check_rollups.monitor_id')
                ->addSelect('check_rollups.monitor_id')
                ->get();
        }

        /** @var array<int, array<string, int|null>> $merged */
        $merged = [];
        foreach ($parts as $rows) {
            foreach ($rows as $row) {
                $monitorId = self::int($row->monitor_id);
                $merged[$monitorId] = $this->merge($merged[$monitorId] ?? null, (array) $row);
            }
        }

        $records = [];
        foreach ($merged as $monitorId => $totals) {
            $records[] = $this->record($monitorId, CheckRollup::BUCKET_DAY, $dayStart, $totals);
        }

        DB::transaction(function () use ($records, $workspaceId, $timezone, $dayStart, $dayEnd, $monitorIds): void {
            $this->upsertChanged($records);
            $this->dropOverlappingDays($workspaceId, $timezone, $dayStart, $dayEnd, $monitorIds);
        });
    }

    /**
     * Select the sums of a set of rollup rows.
     */
    private function summed(Builder $query): Builder
    {
        $table = 'check_rollups.';
        foreach (self::COUNTERS as $column) {
            $query->selectRaw("sum({$table}{$column}) as {$column}");
        }

        return $query
            ->selectRaw("min({$table}latency_min) as latency_min")
            ->selectRaw("max({$table}latency_max) as latency_max")
            ->selectRaw("sum({$table}latency_sum) as latency_sum")
            ->selectRaw("sum({$table}latency_count) as latency_count");
    }

    /**
     * @param  list<int>|null  $monitorIds
     */
    private function forWorkspace(Builder $query, int $workspaceId, ?array $monitorIds = null): Builder
    {
        return $query
            ->join('monitors', 'monitors.id', '=', 'check_rollups.monitor_id')
            ->where('monitors.workspace_id', $workspaceId)
            ->when($monitorIds !== null, fn (Builder $q) => $q->whereIn('check_rollups.monitor_id', $monitorIds ?? []));
    }

    /**
     * @param  array<string, int|null>|null  $into
     * @param  array<mixed>  $row  An aggregated database row.
     * @return array<string, int|null>
     */
    private function merge(?array $into, array $row): array
    {
        $merged = [];
        foreach ([...self::COUNTERS, 'latency_sum', 'latency_count'] as $column) {
            $merged[$column] = ($into[$column] ?? 0) + self::int($row[$column] ?? 0);
        }
        $min = array_filter([$into['latency_min'] ?? null, self::nullableInt($row['latency_min'] ?? null)], is_int(...));
        $max = array_filter([$into['latency_max'] ?? null, self::nullableInt($row['latency_max'] ?? null)], is_int(...));
        $merged['latency_min'] = $min === [] ? null : min($min);
        $merged['latency_max'] = $max === [] ? null : max($max);

        return $merged;
    }

    /**
     * @param  array<mixed>  $values  Aggregated counters, from a database row or merged in PHP.
     * @return array<string, int|string|null>
     */
    private function record(int $monitorId, string $bucket, int $start, array $values): array
    {
        $record = ['monitor_id' => $monitorId, 'bucket' => $bucket, 'bucket_start' => $start];
        foreach (self::COUNTERS as $column) {
            $record[$column] = self::int($values[$column] ?? 0);
        }
        $count = self::int($values['latency_count'] ?? 0);
        $sum = self::int($values['latency_sum'] ?? 0);
        $record['latency_min'] = $count > 0 ? self::nullableInt($values['latency_min'] ?? null) : null;
        $record['latency_max'] = $count > 0 ? self::nullableInt($values['latency_max'] ?? null) : null;
        $record['latency_sum'] = $sum;
        $record['latency_count'] = $count;
        $record['latency_avg'] = $count > 0 ? (int) round($sum / $count) : null;

        return $record;
    }

    /**
     * Write the records whose values differ from the stored rows. All records share one bucket and start. Reads
     * and writes go in chunks of UPSERT_CHUNK rows, far below the 65 535 placeholders PostgreSQL and MySQL allow
     * per statement.
     *
     * @param  list<array<string, int|string|null>>  $records
     */
    private function upsertChanged(array $records): void
    {
        foreach (array_chunk($records, self::UPSERT_CHUNK) as $chunk) {
            $existing = DB::table('check_rollups')
                ->where('bucket', $chunk[0]['bucket'])
                ->where('bucket_start', $chunk[0]['bucket_start'])
                ->whereIn('monitor_id', array_column($chunk, 'monitor_id'))
                ->get(['monitor_id', ...self::VALUES])
                ->keyBy(fn (object $row): int => self::int($row->monitor_id));

            $changed = [];
            foreach ($chunk as $record) {
                $stored = $existing->get(self::int($record['monitor_id']));
                if (! is_object($stored) || $this->differs($record, (array) $stored)) {
                    $changed[] = $record;
                }
            }

            if ($changed !== []) {
                DB::table('check_rollups')->upsert($changed, ['monitor_id', 'bucket', 'bucket_start'], self::VALUES);
            }
        }
    }

    /**
     * @param  array<string, int|string|null>  $record
     * @param  array<mixed>  $stored
     */
    private function differs(array $record, array $stored): bool
    {
        foreach (self::VALUES as $column) {
            if ($record[$column] !== self::nullableInt($stored[$column] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $monitorIds
     * @return array<int, int> Monitor id => workspace id, for monitors that still exist.
     */
    private function workspacesOfMonitors(array $monitorIds): array
    {
        $map = [];
        foreach (array_chunk($monitorIds, 1000) as $chunk) {
            foreach (DB::table('monitors')->whereIn('id', $chunk)->get(['id', 'workspace_id']) as $monitor) {
                $map[self::int($monitor->id)] = self::int($monitor->workspace_id);
            }
        }

        return $map;
    }

    /**
     * The local days of a timezone that overlap [from, to), as [start, end) epoch pairs.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function localDays(string $timezone, int $from, int $to): array
    {
        $days = [];
        for ($start = $this->dayStart($timezone, $from); $start < $to; $start = $end) {
            $end = $this->nextDayStart($timezone, $start);
            $days[] = [$start, $end];
        }

        return $days;
    }

    private function dayStart(string $timezone, int $timestamp): int
    {
        return CarbonImmutable::createFromTimestamp($timestamp, $timezone)->startOfDay()->getTimestamp();
    }

    private function nextDayStart(string $timezone, int $dayStart): int
    {
        return CarbonImmutable::createFromTimestamp($dayStart, $timezone)->addDay()->startOfDay()->getTimestamp();
    }

    private function minuteRetentionSeconds(): int
    {
        return config()->integer('monitoring.retention.minute_hours', 48) * 3600;
    }

    /**
     * Database drivers return aggregates as integers or numeric strings.
     */
    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
