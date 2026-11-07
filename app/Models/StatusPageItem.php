<?php

namespace App\Models;

use Database\Factories\StatusPageItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A monitor shown as a component of a status page section.
 *
 * @property int $id
 * @property int $status_page_section_id
 * @property int $monitor_id
 * @property string|null $display_name
 * @property string|null $description
 * @property int $position
 * @property bool $expand_children
 * @property bool $show_uptime
 */
#[Fillable([
    'status_page_section_id', 'monitor_id', 'display_name', 'description', 'position', 'expand_children', 'show_uptime',
])]
class StatusPageItem extends Model
{
    /** @use HasFactory<StatusPageItemFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'expand_children' => false,
        'show_uptime' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expand_children' => 'boolean',
            'show_uptime' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<StatusPageSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(StatusPageSection::class, 'status_page_section_id');
    }

    /**
     * @return BelongsTo<Monitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }
}
