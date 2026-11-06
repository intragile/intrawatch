<?php

namespace App\Monitoring\Engine;

use App\Models\CheckRollup;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates raw check results into minute rollups, minute rollups into hour rollups, and both into day rollups
 * per workspace-local day. Every level is recomputed from the level below and upserted, so a run is idempotent
 * and late results are absorbed by the next run that covers their minute.
 *
 * Load profile: a run touches about six minutes of raw rows (one minute per query), the hours those minutes
 * belong to, and the local days of the workspaces involved. All aggregation happens in SQL; PHP only sees one
 * aggregated row per monitor and bucket.
 */
final class RollupBuilder
{
    public const WATERMARK_KEY = 'rollup:watermark';

    /** Seconds before the watermark that every run re-aggregates, to absorb late results. */
    public const LOOKBACK = 300;

    /** Most seconds of minute buckets a single run aggregates; a longer backlog is worked off over several runs. */
    public const MAX_CATCH_UP = 3600;

    private const UPSERT_CHUNK = 500;

    private const COUNTERS = ['total', 'up', 'down', 'degraded', 'pending', 'maintenance'];

    public function __construct(private readonly Cache $cache) {}

    /**
     * Aggregate the minute buckets in [watermark - LOOKBACK, now - 1 min), the hours they belong to and the
     * workspace-local days those hours touch, then advance the watermark.
     */
    public function run(CarbonImmutable $now): void
    {
        // Minute buckets that start before now - 60 s.
        $end = intdiv($now->getTimestamp() - 60 + 59, 60) * 60;

        $oldestRaw = self::nullableInt(DB::table('check_results')->min('bucket_minute'));
        if ($oldestRaw === null) {
            $this->cache->forever(self::WATERMARK_KEY, $end);

            return;
        }

        $start = max($this->resumePoint($oldestRaw), $oldestRaw);
        $end = min($end, $start + self::MAX_CATCH_UP);
        if ($start >= $end) {
            return;
        }

        $monitorIds = [];
        for ($minute = $start; $minute < $end; $minute += 60) {
            foreach ($this->buildMinute($minute) as $monitorId) {
                $monitorIds[$monitorId] = true;
            }
        }

        $firstHour = $start - $start % 3600;
        $lastHourEnd = ($end - 1) - ($end - 1) % 3600 + 3600;
        for ($hour = $firstHour; $hour < $lastHourEnd; $hour += 3600) {
            $this->buildHour($hour);
        }

        $minuteCutoff = $now->getTimestamp() - $this->minuteRetentionSeconds();
        foreach ($this->workspacesOf(array_keys($monitorIds)) as $workspaceId => $timezone) {
            foreach ($this->localDays($timezone, $firstHour, $lastHourEnd) as [$dayStart, $dayEnd]) {
                $this->buildDay($workspaceId, $dayStart, $dayEnd, $dayStart >= $minuteCutoff);
            }
        }

        $this->cache->forever(self::WATERMARK_KEY, $end);
    }

    /**
     * Rebuild the day rollups of a workspace for its current timezone from the rollups still retained. Days whose
     * minute rows are retained are exact; older days are summed from hour rows, which is exact for whole-hour
     * offsets and approximate (shifted by the sub-hour part of the offset) for offsets such as +05:30. Day rows
     * older than the retained hour rows keep the boundaries of the timezone they were built in.
     */
    public function rebuildDays(Workspace $workspace): void
    {
        $now = CarbonImmutable::now();
        $timezone = $workspace->timezone;

        $oldestHour = self::nullableInt($this->forWorkspace(DB::table('check_rollups'), $workspace->id)
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

        $minuteCutoff = $now->getTimestamp() - $this->minuteRetentionSeconds();
        $starts = [];
        foreach ($this->localDays($timezone, $firstDay, $now->getTimestamp()) as [$dayStart, $dayEnd]) {
            $this->buildDay($workspace->id, $dayStart, $dayEnd, $dayStart >= $minuteCutoff);
            $starts[] = $dayStart;
        }

        // Day rows of the old timezone inside the rebuilt range no longer match a local day.
        $monitorIds = DB::table('monitors')->where('workspace_id', $workspace->id)->orderBy('id')->pluck('id');
        foreach ($monitorIds->chunk(self::UPSERT_CHUNK) as $chunk) {
            DB::table('check_rollups')
                ->whereIn('monitor_id', $chunk->values()->all())
                ->where('bucket', CheckRollup::BUCKET_DAY)
                ->where('bucket_start', '>=', $firstDay)
                ->when($starts !== [], fn (Builder $q) => $q->whereNotIn('bucket_start', $starts))
                ->delete();
        }
    }

    /**
     * Where the next run starts: shortly before the stored watermark, or, when the watermark was lost, at the
     * newest minute rollup, or at the oldest raw result when no minute rollup exists.
     */
    private function resumePoint(int $oldestRaw): int
    {
        $watermark = $this->cache->get(self::WATERMARK_KEY);
        if (is_numeric($watermark)) {
            return (int) $watermark - self::LOOKBACK;
        }

        return self::nullableInt(DB::table('check_rollups')->where('bucket', CheckRollup::BUCKET_MINUTE)->max('bucket_start'))
            ?? $oldestRaw;
    }

    /**
     * @return list<int> The monitors that have results in this minute.
     */
    private function buildMinute(int $minute): array
    {
        $rows = DB::table('check_results')
            ->where('bucket_minute', $minute)
            ->groupBy('monitor_id')
            ->select('monitor_id')
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when status = 'up' then 1 else 0 end) as up")
            ->selectRaw("sum(case when status = 'down' then 1 else 0 end) as down")
            ->selectRaw("sum(case when status = 'degraded' then 1 else 0 end) as degraded")
            ->selectRaw("sum(case when status = 'pending' then 1 else 0 end) as pending")
            ->selectRaw("sum(case when status = 'maintenance' then 1 else 0 end) as maintenance")
            ->selectRaw('min(latency_ms) as latency_min')
            ->selectRaw('max(latency_ms) as latency_max')
            ->selectRaw('coalesce(sum(latency_ms), 0) as latency_sum')
            ->selectRaw('count(latency_ms) as latency_count')
            ->get();

        $records = [];
        $monitorIds = [];
        foreach ($rows as $row) {
            $monitorId = self::int($row->monitor_id);
            $records[] = $this->record($monitorId, CheckRollup::BUCKET_MINUTE, $minute, (array) $row);
            $monitorIds[] = $monitorId;
        }
        $this->upsert($records);

        return $monitorIds;
    }

    private function buildHour(int $hour): void
    {
        $rows = $this->summed(DB::table('check_rollups'))
            ->where('bucket', CheckRollup::BUCKET_MINUTE)
            ->where('bucket_start', '>=', $hour)
            ->where('bucket_start', '<', $hour + 3600)
            ->groupBy('monitor_id')
            ->addSelect('monitor_id')
            ->get();

        $records = [];
        foreach ($rows as $row) {
            $records[] = $this->record(self::int($row->monitor_id), CheckRollup::BUCKET_HOUR, $hour, (array) $row);
        }
        $this->upsert($records);
    }

    /**
     * Aggregate one local day of a workspace. Whole hours inside the day come from hour rows; when the day does
     * not start on a whole hour (offsets such as +05:30), the partial hours at either end come from minute rows.
     * Because hour rows are exact sums of minute rows, this equals summing the day's minute rows while reading
     * at most 25 hour rows and 60 minute rows per monitor.
     *
     * Without minute rows ($exact false), the day is summed from the hour rows that start inside it, which is
     * approximate for sub-hour offsets.
     */
    private function buildDay(int $workspaceId, int $dayStart, int $dayEnd, bool $exact): void
    {
        $hourFrom = $exact ? intdiv($dayStart + 3599, 3600) * 3600 : $dayStart;
        $hourTo = $exact ? $dayEnd - $dayEnd % 3600 : $dayEnd;

        $parts = [
            $this->summed($this->forWorkspace(DB::table('check_rollups'), $workspaceId))
                ->where('check_rollups.bucket', CheckRollup::BUCKET_HOUR)
                ->where('check_rollups.bucket_start', '>=', $hourFrom)
                ->where('check_rollups.bucket_start', '<', $hourTo)
                ->groupBy('check_rollups.monitor_id')
                ->addSelect('check_rollups.monitor_id')
                ->get(),
        ];

        if ($exact && ($hourFrom > $dayStart || $hourTo < $dayEnd)) {
            $parts[] = $this->summed($this->forWorkspace(DB::table('check_rollups'), $workspaceId))
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
        $this->upsert($records);
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

    private function forWorkspace(Builder $query, int $workspaceId): Builder
    {
        return $query
            ->join('monitors', 'monitors.id', '=', 'check_rollups.monitor_id')
            ->where('monitors.workspace_id', $workspaceId);
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
     * @param  list<array<string, int|string|null>>  $records
     */
    private function upsert(array $records): void
    {
        $update = [...self::COUNTERS, 'latency_min', 'latency_max', 'latency_avg', 'latency_sum', 'latency_count'];
        foreach (array_chunk($records, self::UPSERT_CHUNK) as $chunk) {
            DB::table('check_rollups')->upsert($chunk, ['monitor_id', 'bucket', 'bucket_start'], $update);
        }
    }

    /**
     * @param  list<int>  $monitorIds
     * @return array<int, string> Workspace id => timezone.
     */
    private function workspacesOf(array $monitorIds): array
    {
        $workspaceIds = [];
        foreach (array_chunk($monitorIds, 1000) as $chunk) {
            foreach (DB::table('monitors')->whereIn('id', $chunk)->distinct()->pluck('workspace_id') as $id) {
                $workspaceIds[self::int($id)] = true;
            }
        }
        if ($workspaceIds === []) {
            return [];
        }

        $timezones = [];
        foreach (array_chunk(array_keys($workspaceIds), 1000) as $chunk) {
            foreach (DB::table('workspaces')->whereIn('id', $chunk)->get(['id', 'timezone']) as $workspace) {
                $timezones[self::int($workspace->id)] = is_string($workspace->timezone) ? $workspace->timezone : 'UTC';
            }
        }

        return $timezones;
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
