<?php

namespace App\Models;

use App\Enums\MonitorStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One stored check result. Rows are written in bulk by the result recorder and removed by retention jobs.
 *
 * @property int $id
 * @property int $monitor_id
 * @property MonitorStatus $status
 * @property int|null $latency_ms
 * @property string|null $message
 * @property array<string, mixed>|null $facts
 * @property Carbon $checked_at
 * @property int|null $drift_ms
 * @property int $bucket_minute
 */
class CheckResult extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monitor_id' => 'integer',
            'status' => MonitorStatus::class,
            'latency_ms' => 'integer',
            'facts' => 'array',
            'checked_at' => 'datetime',
            'drift_ms' => 'integer',
            'bucket_minute' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Monitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }
}
