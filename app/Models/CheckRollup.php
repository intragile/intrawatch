<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aggregated check results of one monitor over a minute, an hour or a workspace-local day.
 *
 * `latency_sum` and `latency_count` are kept so that coarser buckets can be summed exactly; `latency_avg` is
 * derived from them when the row is written.
 *
 * @property int $id
 * @property int $monitor_id
 * @property string $bucket
 * @property int $bucket_start
 * @property int $total
 * @property int $up
 * @property int $down
 * @property int $degraded
 * @property int $pending
 * @property int $maintenance
 * @property int|null $latency_min
 * @property int|null $latency_max
 * @property int|null $latency_avg
 * @property int $latency_sum
 * @property int $latency_count
 */
class CheckRollup extends Model
{
    public const BUCKET_MINUTE = 'minute';

    public const BUCKET_HOUR = 'hour';

    public const BUCKET_DAY = 'day';

    public const BUCKETS = [self::BUCKET_MINUTE, self::BUCKET_HOUR, self::BUCKET_DAY];

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monitor_id' => 'integer',
            'bucket_start' => 'integer',
            'total' => 'integer',
            'up' => 'integer',
            'down' => 'integer',
            'degraded' => 'integer',
            'pending' => 'integer',
            'maintenance' => 'integer',
            'latency_min' => 'integer',
            'latency_max' => 'integer',
            'latency_avg' => 'integer',
            'latency_sum' => 'integer',
            'latency_count' => 'integer',
        ];
    }

    /**
     * Share of checks during which the target was reachable. Maintenance is excluded from both sides and
     * pending counts as available; null when no check outside maintenance exists.
     */
    public function availability(): ?float
    {
        $denominator = (int) $this->total - (int) $this->maintenance;
        if ($denominator <= 0) {
            return null;
        }

        return ((int) $this->up + (int) $this->degraded + (int) $this->pending) / $denominator;
    }

    /**
     * @return BelongsTo<Monitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }
}
