<?php

namespace App\Models;

use App\Enums\MaintenanceStrategy;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\MaintenanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A scheduled period during which the attached monitors are muted.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $title
 * @property string|null $description
 * @property MaintenanceStrategy $strategy
 * @property string|null $timezone
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property string|null $start_time
 * @property int|null $duration_minutes
 * @property int|null $every_days
 * @property list<int>|null $weekdays
 * @property list<int>|null $month_days
 * @property string|null $cron
 * @property string|null $valid_from Local date, `Y-m-d`.
 * @property string|null $valid_until Local date, `Y-m-d`, inclusive.
 * @property bool $active
 */
#[Fillable([
    'workspace_id', 'title', 'description', 'strategy', 'timezone', 'starts_at', 'ends_at', 'start_time',
    'duration_minutes', 'every_days', 'weekdays', 'month_days', 'cron', 'valid_from', 'valid_until', 'active',
])]
class Maintenance extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<MaintenanceFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'active' => true,
    ];

    protected static function booted(): void
    {
        static::deleting(function (Maintenance $maintenance): void {
            DB::table('status_page_links')
                ->where('linkable_type', 'maintenance')
                ->where('linkable_id', $maintenance->id)
                ->delete();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'strategy' => MaintenanceStrategy::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'weekdays' => 'array',
            'month_days' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * Local calendar dates stay plain `Y-m-d` strings so no timezone conversion can shift them.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function validFrom(): Attribute
    {
        return $this->localDate();
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function validUntil(): Attribute
    {
        return $this->localDate();
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    private function localDate(): Attribute
    {
        return Attribute::make(
            get: static fn (mixed $value): ?string => is_string($value) ? substr($value, 0, 10) : null,
            set: static fn (mixed $value): ?string => match (true) {
                $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                is_string($value) => $value,
                default => null,
            },
        );
    }

    /**
     * @return BelongsToMany<Monitor, $this>
     */
    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class, 'maintenance_monitor');
    }

    /**
     * @return BelongsToMany<StatusPage, $this>
     */
    public function statusPages(): BelongsToMany
    {
        return $this->belongsToMany(StatusPage::class, 'status_page_links', 'linkable_id', 'status_page_id')
            ->wherePivot('linkable_type', 'maintenance');
    }
}
