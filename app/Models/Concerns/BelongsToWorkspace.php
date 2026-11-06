<?php

namespace App\Models\Concerns;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For models that live inside one workspace (a `workspace_id` column).
 *
 * Expose such models below `/workspaces/{workspace}/...` with a `{workspace}->{plural}()` relation so the
 * scoped route bindings resolve them through the workspace, and filter ad-hoc queries with `forWorkspace()`.
 */
trait BelongsToWorkspace
{
    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Restrict the query to rows of the given workspace.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForWorkspace(Builder $query, Workspace|int $workspace): void
    {
        $query->where($this->qualifyColumn('workspace_id'), $workspace instanceof Workspace ? $workspace->id : $workspace);
    }
}
