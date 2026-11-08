<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkspaceAbilities;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

class MeController extends Controller
{
    /**
     * @response array{data: array{id: int, name: string, email: string, two_factor_enabled: bool, can_create_workspace: bool, workspaces: list<array{id: string, name: string, slug: string, timezone: string, role: \App\Enums\WorkspaceRole, channel: string|null, can: array{view: bool, update: bool, delete: bool, viewMembers: bool, manageMembers: bool, viewAudit: bool, viewMonitors: bool, manageMonitors: bool, viewNotifications: bool, manageNotifications: bool, viewIncidents: bool, manageIncidents: bool, viewStatusPages: bool, manageStatusPages: bool, manageCustomDomains: bool}}>}|array{id: int, name: string, workspaces: list<array{id: string, name: string, slug: string, timezone: string, role: \App\Enums\WorkspaceRole, channel: string|null, can: array{view: bool, update: bool, delete: bool, viewMembers: bool, manageMembers: bool, viewAudit: bool, viewMonitors: bool, manageMonitors: bool, viewNotifications: bool, manageNotifications: bool, viewIncidents: bool, manageIncidents: bool, viewStatusPages: bool, manageStatusPages: bool, manageCustomDomains: bool}}>, token: array{name: string, abilities: list<string>|null, expires_at: string|null}}}
     */
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $token = $user->authenticatedByToken() ? $user->currentAccessToken() : null;

        return response()->json(['data' => $token instanceof PersonalAccessToken
            ? self::tokenPayload($user, $token)
            : self::payload($user)]);
    }

    /**
     * Reduced identity for token callers: no e-mail, only the token's own workspace.
     *
     * @return array<string, mixed>
     */
    private static function tokenPayload(User $user, PersonalAccessToken $token): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'workspaces' => self::workspaces($user, $user->scopedWorkspaceId()),
            'token' => [
                'name' => $token->name,
                'abilities' => $token->abilities,
                'expires_at' => $token->expires_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
            // Whether the API lets this user create a workspace (see `WorkspacePolicy::create`).
            'can_create_workspace' => Gate::forUser($user)->allows('create', Workspace::class),
            'workspaces' => self::workspaces($user),
        ];
    }

    /**
     * @return list<array{id: string, name: string, slug: string, timezone: string, role: string, channel: string|null, can: array{view: bool, update: bool, delete: bool, viewMembers: bool, manageMembers: bool, viewAudit: bool, viewMonitors: bool, manageMonitors: bool, viewNotifications: bool, manageNotifications: bool, viewIncidents: bool, manageIncidents: bool, viewStatusPages: bool, manageStatusPages: bool, manageCustomDomains: bool}}>
     */
    private static function workspaces(User $user, ?int $onlyWorkspaceId = null): array
    {
        $query = $user->workspaces()->orderBy('workspaces.name');

        if ($onlyWorkspaceId !== null) {
            $query->where('workspaces.id', $onlyWorkspaceId);
        }

        return array_values($query->get()
            ->map(function (Workspace $workspace) use ($user): array {
                /** @var Membership $pivot */
                $pivot = $workspace->getRelation('pivot');

                return [
                    'id' => $workspace->public_id,
                    'name' => $workspace->name,
                    'slug' => $workspace->slug,
                    'timezone' => $workspace->timezone,
                    'role' => $pivot->role->value,
                    'channel' => WorkspaceAbilities::channel($user, $workspace),
                    'can' => WorkspaceAbilities::for($user, $workspace),
                ];
            })
            ->all());
    }
}
