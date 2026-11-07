<?php

namespace App\Models;

use App\Alerting\Engine\AlertClass;
use App\Enums\MonitorStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Alert bookkeeping of one monitor: what users were last told, what is observed now and the reminder schedule.
 *
 * @property int $monitor_id
 * @property AlertClass|null $notified
 * @property AlertClass|null $observed
 * @property MonitorStatus|null $current
 * @property Carbon|null $problem_since
 * @property Carbon|null $down_since
 * @property Carbon|null $degraded_since
 * @property bool $flapping
 * @property Carbon|null $flapping_since
 * @property list<int>|null $recent_changes Unix timestamps of recent class changes.
 * @property Carbon|null $last_notified_at
 * @property int $reminders_sent
 * @property bool $suppressed
 * @property Carbon|null $last_signal_at
 * @property Carbon|null $due_at
 * @property Carbon|null $updated_at
 */
class AlertState extends Model
{
    public const CREATED_AT = null;

    protected $primaryKey = 'monitor_id';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'flapping' => false,
        'reminders_sent' => 0,
        'suppressed' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notified' => AlertClass::class,
            'observed' => AlertClass::class,
            'current' => MonitorStatus::class,
            'problem_since' => 'datetime',
            'down_since' => 'datetime',
            'degraded_since' => 'datetime',
            'flapping' => 'boolean',
            'flapping_since' => 'datetime',
            'recent_changes' => 'array',
            'last_notified_at' => 'datetime',
            'reminders_sent' => 'integer',
            'suppressed' => 'boolean',
            'last_signal_at' => 'datetime',
            'due_at' => 'datetime',
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
