<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string $color
 */
#[Fillable(['workspace_id', 'name', 'color'])]
class Tag extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<TagFactory> */
    use HasFactory;

    /**
     * @return BelongsToMany<Monitor, $this>
     */
    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class)->withPivot('value');
    }
}
