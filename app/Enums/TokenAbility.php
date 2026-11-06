<?php

namespace App\Enums;

enum TokenAbility: string
{
    case WorkspaceRead = 'workspace:read';
    case WorkspaceManage = 'workspace:manage';
    case MembersManage = 'members:manage';
    case AuditRead = 'audit:read';

    /**
     * The lowest workspace role allowed to hold this ability; a token can never exceed its creator's role.
     */
    public function minimumRole(): WorkspaceRole
    {
        return match ($this) {
            self::WorkspaceRead => WorkspaceRole::Viewer,
            self::WorkspaceManage, self::MembersManage, self::AuditRead => WorkspaceRole::Admin,
        };
    }
}
