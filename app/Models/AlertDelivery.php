<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The delivery of one or more alert events to one channel.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $notification_channel_id
 * @property list<int> $alert_event_ids
 * @property DeliveryStatus $status
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $sent_at
 */
class AlertDelivery extends Model
{
    protected $guarded = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'workspace_id' => 'integer',
            'notification_channel_id' => 'integer',
            'alert_event_ids' => 'array',
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<NotificationChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'notification_channel_id');
    }
}
