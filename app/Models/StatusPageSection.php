<?php

namespace App\Models;

use Database\Factories\StatusPageSectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A titled group of components on a status page.
 *
 * @property int $id
 * @property int $status_page_id
 * @property string $name
 * @property string|null $description
 * @property int $position
 */
#[Fillable(['status_page_id', 'name', 'description', 'position'])]
class StatusPageSection extends Model
{
    /** @use HasFactory<StatusPageSectionFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return BelongsTo<StatusPage, $this>
     */
    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }

    /**
     * @return HasMany<StatusPageItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StatusPageItem::class)->orderBy('position')->orderBy('id');
    }
}
