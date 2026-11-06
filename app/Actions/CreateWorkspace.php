<?php

namespace App\Actions;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CreateWorkspace
{
    /**
     * Create a workspace and make the given user its owner.
     *
     * A concurrent request may claim the chosen slug first; the slug is then
     * chosen again once, which yields the next free suffix.
     */
    public function handle(User $owner, string $name): Workspace
    {
        try {
            return $this->create($owner, $name);
        } catch (UniqueConstraintViolationException) {
            return $this->create($owner, $name);
        }
    }

    private function create(User $owner, string $name): Workspace
    {
        $slug = Workspace::uniqueSlug($name);

        return DB::transaction(function () use ($owner, $name, $slug): Workspace {
            $workspace = Workspace::create(['name' => $name, 'slug' => $slug]);
            $owner->workspaces()->attach($workspace, ['role' => WorkspaceRole::Owner->value]);

            return $workspace;
        });
    }
}
