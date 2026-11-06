<?php

namespace App\Models;

use App\Enums\MonitorStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Current runtime state of a monitor; one row per monitor, rewritten as results arrive.
 *
 * @property int $monitor_id
 * @property MonitorStatus $status
 * @property Carbon $status_since
 * @property Carbon|null $last_checked_at
 * @property int $consecutive_failures
 * @property int|null $last_latency_ms
 * @property string|null $last_message
 * @property array<string, mixed>|null $watch
 * @property Carbon|null $last_ping_at
 * @property Carbon|null $run_started_at
 * @property Carbon|null $updated_at
 */
class MonitorState extends Model
{
    public const CREATED_AT = null;

    protected $primaryKey = 'monitor_id';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'consecutive_failures' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MonitorStatus::class,
            'status_since' => 'datetime',
            'last_checked_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'last_latency_ms' => 'integer',
            'watch' => 'array',
            'last_ping_at' => 'datetime',
            'run_started_at' => 'datetime',
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
