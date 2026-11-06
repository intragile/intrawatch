<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => self::payload($user)]);
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
            'workspaces' => $user->workspaces()->orderBy('workspaces.name')->get()
                ->map(function (Workspace $workspace): array {
                    /** @var Membership $pivot */
                    $pivot = $workspace->getRelation('pivot');

                    return [
                        'id' => $workspace->public_id,
                        'name' => $workspace->name,
                        'slug' => $workspace->slug,
                        'role' => $pivot->role->value,
                    ];
                })
                ->all(),
        ];
    }
}
