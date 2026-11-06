<?php

namespace App\Models;

use App\Enums\MonitorStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An entry of a monitor's history: a status change or a change of a watched value.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $monitor_id
 * @property string $kind
 * @property MonitorStatus|null $from_status
 * @property MonitorStatus|null $to_status
 * @property string|null $key
 * @property string|null $old_value
 * @property string|null $new_value
 * @property string $message
 * @property Carbon $created_at
 */
class MonitorEvent extends Model
{
    public const KIND_STATUS_CHANGED = 'status_changed';

    public const KIND_CHANGED = 'changed';

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
            'from_status' => MonitorStatus::class,
            'to_status' => MonitorStatus::class,
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
