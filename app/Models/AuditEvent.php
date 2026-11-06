<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One immutable entry of the audit trail. Rows are only ever inserted.
 *
 * @property int $id
 * @property int|null $workspace_id
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, array{0: mixed, 1: mixed}>|null $changes
 * @property string|null $ip
 * @property CarbonImmutable $created_at
 */
class AuditEvent extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Audit events are append-only and cannot be updated.');
        });
        static::deleting(function (): never {
            throw new LogicException('Audit events are append-only and cannot be deleted.');
        });
    }
}
