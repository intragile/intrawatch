<?php

namespace App\Support;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use LogicException;

/**
 * Holds the workspace and the caller's role for the request being served.
 */
class CurrentWorkspace
{
    private ?Workspace $workspace = null;

    private ?WorkspaceRole $role = null;

    private ?int $userId = null;

    public function set(Workspace $workspace, WorkspaceRole $role, ?User $user = null): void
    {
        $this->workspace = $workspace;
        $this->role = $role;
        $this->userId = $user?->id;
    }

    public function isResolved(): bool
    {
        return $this->workspace !== null;
    }

    public function get(): Workspace
    {
        return $this->workspace ?? throw new LogicException('No workspace has been resolved for this request.');
    }

    public function role(): WorkspaceRole
    {
        return $this->role ?? throw new LogicException('No workspace has been resolved for this request.');
    }

    /**
     * The role of the given user in the given workspace, taken from the resolved request state when it
     * describes that very user and workspace, and read from the database otherwise.
     */
    public function roleOf(User $user, Workspace $workspace): ?WorkspaceRole
    {
        if ($this->workspace !== null && $this->userId !== null
            && $this->userId === $user->id && $this->workspace->is($workspace)) {
            return $this->role;
        }

        return $user->roleIn($workspace);
    }
}
