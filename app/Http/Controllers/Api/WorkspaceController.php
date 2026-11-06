<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateWorkspace;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class WorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $user->workspaces()->orderBy('workspaces.name')->get()
            ->map(function (Workspace $workspace): array {
                /** @var Membership $pivot */
                $pivot = $workspace->getRelation('pivot');

                return $this->payload($workspace, $pivot->role);
            })
            ->all();

        return response()->json(['data' => $data]);
    }

    public function store(Request $request, CreateWorkspace $createWorkspace): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $request->validate(['name' => ['required', 'string', 'max:120']]);
        $name = $request->string('name')->toString();

        $workspace = $createWorkspace->handle($user, $name);

        return response()->json(['data' => $this->payload($workspace, WorkspaceRole::Owner)], 201);
    }

    public function update(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('update', $workspace);
        $request->validate(['name' => ['required', 'string', 'max:120']]);
        $name = $request->string('name')->toString();

        $workspace->update(['name' => $name]);

        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->payload($workspace, $user->roleIn($workspace))]);
    }

    public function destroy(Workspace $workspace): Response
    {
        Gate::authorize('delete', $workspace);

        $workspace->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Workspace $workspace, ?WorkspaceRole $role): array
    {
        return [
            'id' => $workspace->public_id,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'role' => $role?->value,
        ];
    }
}
