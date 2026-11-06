<?php

namespace App\Http\Controllers\Api;

use App\Enums\TokenAbility;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Manages the caller's own API tokens for one workspace. Only session requests may use it, so a token
 * can never mint or revoke tokens.
 */
class TokenController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeSession($request);

        $data = $this->user($request)->tokens()
            ->where('workspace_id', $workspace->id)
            ->orderBy('id')
            ->get()
            ->map(fn (PersonalAccessToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
            ])
            ->all();

        return response()->json(['data' => $data]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeSession($request);

        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['required', 'string', 'distinct', Rule::enum(TokenAbility::class)],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $abilities = array_values(array_filter($request->array('abilities'), is_string(...)));

        foreach ($abilities as $ability) {
            $minimum = TokenAbility::from($ability)->minimumRole();

            if (! $this->current->role()->atLeast($minimum)) {
                throw ValidationException::withMessages([
                    'abilities' => ["Your role does not allow granting the {$ability} ability."],
                ]);
            }
        }

        $created = DB::transaction(function () use ($request, $workspace, $abilities) {
            $created = $this->user($request)->createToken(
                $request->string('name')->toString(),
                $abilities,
                $request->date('expires_at'),
            );
            $created->accessToken->forceFill(['workspace_id' => $workspace->id])->save();
            Audit::record('token.created', $created->accessToken, [
                'name' => [null, $created->accessToken->name],
                'abilities' => [null, $abilities],
            ]);

            return $created;
        });

        return response()->json([
            'id' => $created->accessToken->getKey(),
            'token' => $created->plainTextToken,
        ], 201);
    }

    public function destroy(Request $request, Workspace $workspace, string $id): Response
    {
        $this->authorizeSession($request);

        $token = $this->user($request)->tokens()
            ->where('workspace_id', $workspace->id)
            ->whereKey($id)
            ->firstOrFail();
        DB::transaction(function () use ($token): void {
            $token->delete();
            Audit::record('token.revoked', $token, ['name' => [$token->name, null]]);
        });

        return response()->noContent();
    }

    private function authorizeSession(Request $request): void
    {
        Gate::authorize('view', $this->current->get());

        abort_if($this->user($request)->authenticatedByToken(), 403, 'API tokens cannot manage API tokens.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : abort(401);
    }
}
