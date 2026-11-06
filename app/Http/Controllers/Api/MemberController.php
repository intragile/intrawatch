<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class MemberController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function index(Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewMembers', $workspace);

        $data = $workspace->memberships()->with('user')->orderBy('id')->get()
            ->map(fn (Membership $membership): array => $this->payload($membership))
            ->all();

        return response()->json(['data' => $data]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('manageMembers', $workspace);

        $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
        ]);
        $role = $request->enum('role', WorkspaceRole::class) ?? throw new LogicException('Validated role is missing.');
        $this->authorizeGrant($role);

        $user = User::where('email', $request->string('email')->toString())->firstOrFail();

        if ($user->roleIn($workspace) !== null) {
            throw ValidationException::withMessages([
                'email' => ['The user is already a member of this workspace.'],
            ]);
        }

        $membership = $workspace->memberships()->create(['user_id' => $user->id, 'role' => $role]);

        return response()->json(['data' => $this->payload($membership->setRelation('user', $user))], 201);
    }

    public function update(Request $request, Workspace $workspace, User $user): JsonResponse
    {
        Gate::authorize('manageMembers', $workspace);

        $request->validate(['role' => ['required', Rule::enum(WorkspaceRole::class)]]);
        $role = $request->enum('role', WorkspaceRole::class) ?? throw new LogicException('Validated role is missing.');
        $membership = $this->membershipOf($workspace, $user);

        $this->authorizeGrant($role);
        $this->authorizeTouching($membership);

        $membership->guardLastOwner($role, function () use ($membership, $role): void {
            $membership->update(['role' => $role]);
        });

        return response()->json(['data' => $this->payload($membership->setRelation('user', $user))]);
    }

    public function destroy(Workspace $workspace, User $user): Response
    {
        Gate::authorize('manageMembers', $workspace);

        $membership = $this->membershipOf($workspace, $user);
        $this->authorizeTouching($membership);

        $membership->guardLastOwner(null, function () use ($membership): void {
            $membership->delete();
        });

        return response()->noContent();
    }

    private function membershipOf(Workspace $workspace, User $user): Membership
    {
        return $workspace->memberships()->where('user_id', $user->id)->firstOrFail();
    }

    /**
     * Only an owner may hand out the owner role.
     */
    private function authorizeGrant(WorkspaceRole $role): void
    {
        abort_if(
            $role === WorkspaceRole::Owner && $this->current->role() !== WorkspaceRole::Owner,
            403,
            'Only an owner can grant the owner role.',
        );
    }

    /**
     * Only an owner may change or remove an existing owner.
     */
    private function authorizeTouching(Membership $membership): void
    {
        abort_if(
            $membership->role === WorkspaceRole::Owner && $this->current->role() !== WorkspaceRole::Owner,
            403,
            'Only an owner can change or remove an owner.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Membership $membership): array
    {
        $user = $membership->user;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $membership->role->value,
        ];
    }
}
