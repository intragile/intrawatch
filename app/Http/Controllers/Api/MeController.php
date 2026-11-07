<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkspaceAbilities;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class MeController extends Controller
{
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
            'workspaces' => self::workspaces($user),
        ];
    }

    /**
     * @return list<array<string, mixed>>
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
                    'role' => $pivot->role->value,
                    'channel' => WorkspaceAbilities::channel($user, $workspace),
                    'can' => WorkspaceAbilities::for($user, $workspace),
                ];
            })
            ->all());
    }
}
