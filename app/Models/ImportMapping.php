<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Links a source entity to the IntraWatch entity it was imported as, which makes re-runs idempotent.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $source
 * @property string $instance_key
 * @property string $entity
 * @property int $source_id
 * @property string $target_type
 * @property int $target_id
 * @property Carbon|null $created_at
 */
#[Fillable(['workspace_id', 'source', 'instance_key', 'entity', 'source_id', 'target_type', 'target_id', 'created_at'])]
class ImportMapping extends Model
{
    use BelongsToWorkspace;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'target_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
