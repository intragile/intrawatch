<?php

namespace App\Models;

use App\Enums\AlertKind;
use App\Enums\MonitorStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One alert raised for a monitor. Survives deletion of the monitor.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $monitor_id
 * @property AlertKind $kind
 * @property MonitorStatus|null $status
 * @property MonitorStatus|null $previous_status
 * @property string $message
 * @property int|null $duration_seconds
 * @property list<array{key: string, old: mixed, new: mixed}>|null $changes
 * @property int|null $suppressed_by_monitor_id
 * @property Carbon $created_at
 */
class AlertEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'workspace_id' => 'integer',
            'monitor_id' => 'integer',
            'kind' => AlertKind::class,
            'status' => MonitorStatus::class,
            'previous_status' => MonitorStatus::class,
            'duration_seconds' => 'integer',
            'changes' => 'array',
            'suppressed_by_monitor_id' => 'integer',
            'created_at' => 'datetime',
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
