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
    case IncidentsRead = 'incidents:read';
    case IncidentsWrite = 'incidents:write';
    case StatusPagesRead = 'status-pages:read';
    case StatusPagesWrite = 'status-pages:write';

    /**
     * The lowest workspace role allowed to hold this ability; a token can never exceed its creator's role.
     */
    public function minimumRole(): WorkspaceRole
    {
        return match ($this) {
            self::WorkspaceRead, self::MonitorsRead, self::NotificationsRead,
            self::IncidentsRead, self::StatusPagesRead => WorkspaceRole::Viewer,
            self::MonitorsWrite, self::NotificationsWrite,
            self::IncidentsWrite, self::StatusPagesWrite => WorkspaceRole::Editor,
            self::WorkspaceManage, self::MembersManage, self::AuditRead => WorkspaceRole::Admin,
        };
    }
}
