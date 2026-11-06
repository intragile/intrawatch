<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property WorkspaceRole $role
 * @property-read User $user
 */
class Membership extends Pivot
{
    use BelongsToWorkspace;

    protected $table = 'memberships';

    public $incrementing = true;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['role' => WorkspaceRole::class];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Run a change to this membership while guaranteeing the workspace keeps an owner.
     *
     * The owner rows are locked for the duration of the transaction so that two
     * concurrent demotions cannot both pass the check.
     *
     * @param  callable(): void  $change
     * @param  WorkspaceRole|null  $newRole  The role after the change, or null when the membership is removed.
     *
     * @throws ValidationException When the change would leave the workspace without an owner.
     */
    public function guardLastOwner(?WorkspaceRole $newRole, callable $change): void
    {
        DB::transaction(function () use ($newRole, $change): void {
            if ($this->role === WorkspaceRole::Owner && $newRole !== WorkspaceRole::Owner) {
                $lockedOwners = static::query()
                    ->where('workspace_id', $this->workspace_id)
                    ->where('role', WorkspaceRole::Owner->value)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);

                if ($lockedOwners->count() <= 1) {
                    throw ValidationException::withMessages([
                        'role' => 'A workspace must keep at least one owner.',
                    ]);
                }
            }

            $change();
        });
    }
}
