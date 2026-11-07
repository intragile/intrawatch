<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Models\Workspace;
use App\Policies\WorkspacePolicy;
use Illuminate\Support\Facades\Gate;

/**
 * The workspace abilities of one caller, as a map of {@see WorkspacePolicy} method names.
 *
 * For a token caller each entry also requires the token ability that guards the matching routes, so the
 * map never promises more than the token can do.
 */
final class WorkspaceAbilities
{
    /** Policy method => token ability required by the routes that use it. */
    private const TOKEN_ABILITY = [
        'view' => 'workspace:read',
        'update' => 'workspace:manage',
        'delete' => 'workspace:manage',
        'viewMembers' => 'workspace:read',
        'manageMembers' => 'members:manage',
        'viewAudit' => 'audit:read',
        'viewMonitors' => 'monitors:read',
        'manageMonitors' => 'monitors:write',
        'viewNotifications' => 'notifications:read',
        'manageNotifications' => 'notifications:write',
        'viewIncidents' => 'incidents:read',
        'manageIncidents' => 'incidents:write',
        'viewStatusPages' => 'status-pages:read',
        'manageStatusPages' => 'status-pages:write',
        'manageCustomDomains' => 'status-pages:write',
    ];

    /**
     * @return array{view: bool, update: bool, delete: bool, viewMembers: bool, manageMembers: bool, viewAudit: bool, viewMonitors: bool, manageMonitors: bool, viewNotifications: bool, manageNotifications: bool, viewIncidents: bool, manageIncidents: bool, viewStatusPages: bool, manageStatusPages: bool, manageCustomDomains: bool}
     */
    public static function for(User $user, Workspace $workspace): array
    {
        $gate = Gate::forUser($user);
        $byToken = $user->authenticatedByToken();
        $can = [];

        foreach (self::TOKEN_ABILITY as $method => $tokenAbility) {
            $can[$method] = $gate->allows($method, $workspace) && (! $byToken || $user->tokenCan($tokenAbility));
        }

        return $can;
    }

    /**
     * The live-updates channel of a workspace, or null when the caller may not listen to it
     * (see routes/channels.php).
     */
    public static function channel(User $user, Workspace $workspace): ?string
    {
        if ($user->authenticatedByToken() && ! $user->tokenCan('monitors:read')) {
            return null;
        }

        return 'workspace.'.$workspace->id;
    }
}
