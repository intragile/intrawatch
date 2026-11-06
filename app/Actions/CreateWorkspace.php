<?php

namespace App\Actions;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class CreateWorkspace
{
    /**
     * Create a workspace and make the given user its owner.
     */
    public function handle(User $owner, string $name): Workspace
    {
        return DB::transaction(function () use ($owner, $name): Workspace {
            $workspace = Workspace::create([
                'name' => $name,
                'slug' => Workspace::uniqueSlug($name),
            ]);
            $owner->workspaces()->attach($workspace, ['role' => WorkspaceRole::Owner->value]);

            return $workspace;
        });
    }
}
