<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One analysis or run of an import from another monitoring system.
 *
 * @property string $id
 * @property int $workspace_id
 * @property int|null $user_id
 * @property string $source
 * @property ImportStatus $status
 * @property array<string, mixed> $options
 * @property string|null $file_path
 * @property string|null $file_name
 * @property int|null $file_size
 * @property array<string, mixed>|null $report
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
#[Fillable([
    'workspace_id', 'user_id', 'source', 'status', 'options', 'file_path', 'file_name', 'file_size',
    'report', 'error', 'started_at', 'finished_at',
])]
class Import extends Model
{
    use BelongsToWorkspace;
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'options' => 'array',
            'report' => 'array',
            'file_size' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
