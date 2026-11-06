<?php

namespace App\Support;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use LogicException;

/**
 * Holds the workspace and the caller's role for the request being served.
 */
class CurrentWorkspace
{
    private ?Workspace $workspace = null;

    private ?WorkspaceRole $role = null;

    public function set(Workspace $workspace, WorkspaceRole $role): void
    {
        $this->workspace = $workspace;
        $this->role = $role;
    }

    public function get(): Workspace
    {
        return $this->workspace ?? throw new LogicException('No workspace has been resolved for this request.');
    }

    public function role(): WorkspaceRole
    {
        return $this->role ?? throw new LogicException('No workspace has been resolved for this request.');
    }
}
