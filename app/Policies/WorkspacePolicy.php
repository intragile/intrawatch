<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Status\Pages\PageResolver;
use App\Support\CurrentWorkspace;

/**
 * Role checks reuse the role that ResolveWorkspace already loaded for the request.
 */
class WorkspacePolicy
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    private function atLeast(User $user, Workspace $workspace, WorkspaceRole $min): bool
    {
        return $this->current->roleOf($user, $workspace)?->atLeast($min) ?? false;
    }

    /**
     * Creating a workspace through the API follows `intrawatch.workspaces.creators`: `anyone`, `none`, or
     * (`owners`, the default, and any unknown value) only users who already own a workspace. API tokens
     * never create workspaces; the command line creates them without this check.
     */
    public function create(User $user): bool
    {
        if ($user->authenticatedByToken()) {
            return false;
        }

        return match (config('intrawatch.workspaces.creators')) {
            'anyone' => true,
            'none' => false,
            default => Membership::query()
                ->where('user_id', $user->id)
                ->where('role', WorkspaceRole::Owner->value)
                ->exists(),
        };
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

    public function viewMonitors(User $user, Workspace $workspace): bool
    {
        return $this->atLeast($user, $workspace, WorkspaceRole::Viewer);
    }

    public function manageMonitors(User $user, Workspace $workspace): bool
    {
        return $this->atLeast($user, $workspace, WorkspaceRole::Editor);
    }

    public function viewNotifications(User $user, Workspace $workspace): bool
    {
        return $this->atLeast($user, $workspace, WorkspaceRole::Viewer);
    }

    public function manageNotifications(User $user, Workspace $workspace): bool
    {
        return $this->atLeast($user, $workspace, WorkspaceRole::Editor);
    }

    public function viewIncidents(User $user, Workspace $workspace): bool
    {
        return $this->atLeast($user, $workspace, WorkspaceRole::Viewer);
    }

    public function manageIncidents(User $user, Workspace $workspace): bool
    {
        return $this->atLeast($user, $workspace, WorkspaceRole::Editor);
    }

    public function viewStatusPages(User $user, Workspace $workspace): bool
    {
        return $this->atLeast($user, $workspace, WorkspaceRole::Viewer);
    }

    public function manageStatusPages(User $user, Workspace $workspace): bool
    {
        return $this->atLeast($user, $workspace, WorkspaceRole::Editor);
    }

    /**
     * A custom domain routes a host name to a page for every visitor of the installation, so only admins set
     * it, and only while the installation allows custom domains at all (`status.custom_domains.allowed`).
     */
    public function manageCustomDomains(User $user, Workspace $workspace): bool
    {
        return PageResolver::customDomainsEnabled() && $this->atLeast($user, $workspace, WorkspaceRole::Admin);
    }
}
