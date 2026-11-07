<?php

namespace App\Enums;

enum TokenAbility: string
{
    case WorkspaceRead = 'workspace:read';
    case WorkspaceManage = 'workspace:manage';
    case MembersManage = 'members:manage';
    case AuditRead = 'audit:read';
    case MonitorsRead = 'monitors:read';
    case MonitorsWrite = 'monitors:write';
    case NotificationsRead = 'notifications:read';
    case NotificationsWrite = 'notifications:write';

    /**
     * The lowest workspace role allowed to hold this ability; a token can never exceed its creator's role.
     */
    public function minimumRole(): WorkspaceRole
    {
        return match ($this) {
            self::WorkspaceRead, self::MonitorsRead, self::NotificationsRead => WorkspaceRole::Viewer,
            self::MonitorsWrite, self::NotificationsWrite => WorkspaceRole::Editor,
            self::WorkspaceManage, self::MembersManage, self::AuditRead => WorkspaceRole::Admin,
        };
    }
}
