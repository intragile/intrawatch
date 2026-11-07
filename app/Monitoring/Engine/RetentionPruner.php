<?php

namespace App\Monitoring\Engine;

use App\Models\CheckRollup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * Deletes history past its retention: raw results, each rollup level, monitor events, alert events and their
 * deliveries, and stale domain lookups.
 * A row exactly at its cutoff is kept.
 */
final class RetentionPruner
{
    public function __construct(private readonly ChunkedDeleter $deleter) {}

    /**
     * @return array<string, int> Deleted rows per data set.
     */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $rawCutoff = $now->subHours(config()->integer('monitoring.retention.raw_hours', 24));
        $rawCutoffText = $rawCutoff->utc()->format('Y-m-d H:i:s');
        // bucket_minute is indexed; every row older than the cutoff sits at or below the cutoff's minute.
        $rawCutoffMinute = intdiv($rawCutoff->getTimestamp(), 60) * 60;

        $dayCutoff = $now->subDays(config()->integer('monitoring.retention.day_days', 730));
        $alertCutoffText = $now->subDays(config()->integer('alerting.retention_days', 90))->utc()->format('Y-m-d H:i:s');

        return [
            'results' => $this->deleter->byIdRange(
                'check_results',
                fn (Builder $q) => $q->where('checked_at', '<', $rawCutoffText),
                fn (Builder $q) => $q->where('bucket_minute', '<=', $rawCutoffMinute),
            ),
            'minute_rollups' => $this->rollups(CheckRollup::BUCKET_MINUTE, $now->subHours(config()->integer('monitoring.retention.minute_hours', 48))),
            'hour_rollups' => $this->rollups(CheckRollup::BUCKET_HOUR, $now->subDays(config()->integer('monitoring.retention.hour_days', 90))),
            'day_rollups' => $this->rollups(CheckRollup::BUCKET_DAY, $dayCutoff),
            'events' => $this->deleter->byIdRange(
                'monitor_events',
                fn (Builder $q) => $q->where('created_at', '<', $dayCutoff->utc()->format('Y-m-d H:i:s')),
            ),
            'alert_deliveries' => $this->deleter->byIdRange(
                'alert_deliveries',
                fn (Builder $q) => $q->where('created_at', '<', $alertCutoffText),
            ),
            'alert_events' => $this->deleter->byIdRange(
                'alert_events',
                fn (Builder $q) => $q->where('created_at', '<', $alertCutoffText),
            ),
            'domain_lookups' => $this->deleter->byKey(
                'domain_lookups',
                'domain',
                fn (Builder $q) => $q->where(
                    'checked_at',
                    '<',
                    $now->subDays(config()->integer('monitoring.retention.domain_lookup_days', 30))->utc()->format('Y-m-d H:i:s'),
                ),
            ),
        ];
    }

    private function rollups(string $bucket, CarbonImmutable $cutoff): int
    {
        $start = $cutoff->getTimestamp();

        return $this->deleter->byIdRange(
            'check_rollups',
            fn (Builder $q) => $q->where('bucket', $bucket)->where('bucket_start', '<', $start),
        );
    }
}
