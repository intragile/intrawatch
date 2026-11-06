<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    private function atLeast(User $user, Workspace $workspace, WorkspaceRole $min): bool
    {
        return $user->roleIn($workspace)?->atLeast($min) ?? false;
    }

    public function view(User $user, Workspace $workspace): bool
    {
        return $this->atLeast($user, $workspace, WorkspaceRole::Viewer);
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $this->atLeast($user, $workspace, WorkspaceRole::Admin);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $this->atLeast($user, $workspace, WorkspaceRole::Owner);
    }

    public function viewMembers(User $user, Workspace $workspace): bool
    {
        return $this->atLeast($user, $workspace, WorkspaceRole::Viewer);
    }

    public function viewAudit(User $user, Workspace $workspace): bool
    {
        return $this->atLeast($user, $workspace, WorkspaceRole::Admin);
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $this->atLeast($user, $workspace, WorkspaceRole::Admin);
    }
}
