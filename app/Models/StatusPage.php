<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\StatusPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * A public status page showing a curated set of monitors.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $slug
 * @property string $title
 * @property string|null $description
 * @property string|null $custom_domain
 * @property bool $published
 * @property string $locale
 * @property string|null $accent_color
 * @property string|null $logo_url
 * @property string|null $favicon_url
 * @property string|null $custom_css
 * @property string|null $footer
 * @property int $bar_days
 * @property int $uptime_days
 * @property int $upcoming_maintenance_days
 * @property int $incident_history_days
 * @property bool $show_latency
 */
#[Fillable([
    'workspace_id', 'slug', 'title', 'description', 'custom_domain', 'published', 'locale', 'accent_color',
    'logo_url', 'favicon_url', 'custom_css', 'footer', 'bar_days', 'uptime_days', 'upcoming_maintenance_days',
    'incident_history_days', 'show_latency',
])]
class StatusPage extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<StatusPageFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'published' => false,
        'locale' => 'en',
        'bar_days' => 90,
        'uptime_days' => 30,
        'upcoming_maintenance_days' => 7,
        'incident_history_days' => 14,
        'show_latency' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published' => 'boolean',
            'show_latency' => 'boolean',
        ];
    }

    /**
     * @return HasMany<StatusPageSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(StatusPageSection::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasManyThrough<StatusPageItem, StatusPageSection, $this>
     */
    public function items(): HasManyThrough
    {
        return $this->hasManyThrough(StatusPageItem::class, StatusPageSection::class)
            ->orderBy('status_page_sections.position')
            ->orderBy('status_page_items.position')
            ->orderBy('status_page_items.id');
    }
}
