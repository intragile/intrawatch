<?php

namespace App\Models;

use App\Enums\ChannelType;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\NotificationChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A destination alerts are delivered to.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property ChannelType $type
 * @property array<string, mixed> $config
 * @property array<string, mixed>|null $secrets
 * @property array{title?: string, body?: string}|null $templates
 * @property bool $is_default
 * @property bool $active
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_failure_at
 * @property string|null $last_error
 */
#[Fillable([
    'workspace_id', 'name', 'type', 'config', 'secrets', 'templates', 'is_default', 'active',
    'last_success_at', 'last_failure_at', 'last_error',
])]
#[Hidden(['secrets'])]
class NotificationChannel extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<NotificationChannelFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_default' => false,
        'active' => true,
        'config' => '[]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ChannelType::class,
            'config' => 'array',
            'secrets' => 'encrypted:array',
            'templates' => 'array',
            'is_default' => 'boolean',
            'active' => 'boolean',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Monitor, $this>
     */
    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class, 'monitor_notification');
    }
}
