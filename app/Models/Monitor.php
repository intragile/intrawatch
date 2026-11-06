<?php

namespace App\Models;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\MonitorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string|null $description
 * @property MonitorType $type
 * @property bool $active
 * @property int $interval_seconds
 * @property int $timeout_seconds
 * @property int $retries
 * @property int $retry_interval_seconds
 * @property int|null $degraded_threshold_ms
 * @property bool $inverted
 * @property array<string, mixed> $config
 * @property array<string, mixed>|null $secrets
 * @property int|null $parent_id
 * @property string|null $push_token
 * @property Carbon|null $check_requested_at
 * @property-read MonitorState $state
 * @property-read Monitor|null $parent
 * @property-read Collection<int, Monitor> $children
 * @property-read Collection<int, Tag> $tags
 */
#[Fillable([
    'workspace_id', 'name', 'description', 'type', 'active', 'interval_seconds', 'timeout_seconds', 'retries',
    'retry_interval_seconds', 'degraded_threshold_ms', 'inverted', 'config', 'secrets', 'parent_id', 'push_token',
    'check_requested_at',
])]
#[Hidden(['secrets', 'push_token'])]
class Monitor extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<MonitorFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'active' => true,
        'inverted' => false,
        'config' => '[]',
    ];

    protected static function booted(): void
    {
        static::created(function (Monitor $monitor): void {
            $monitor->state()->create([
                'status' => $monitor->active ? MonitorStatus::Pending : MonitorStatus::Paused,
                'status_since' => now(),
                'deadline_anchor_at' => now(),
            ]);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MonitorType::class,
            'active' => 'boolean',
            'inverted' => 'boolean',
            'config' => 'array',
            'secrets' => 'encrypted:array',
            'check_requested_at' => 'datetime',
        ];
    }

    /**
     * Ids of the assigned tags, ascending.
     *
     * @return list<int>
     */
    public function tagIds(): array
    {
        $ids = [];
        foreach ($this->tags()->pluck('tags.id') as $id) {
            $ids[] = is_numeric($id) ? (int) $id : 0;
        }
        sort($ids);

        return $ids;
    }

    /**
     * @return HasOne<MonitorState, $this>
     */
    public function state(): HasOne
    {
        return $this->hasOne(MonitorState::class);
    }

    /**
     * @return BelongsTo<Monitor, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Monitor, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withPivot('value');
    }
}
