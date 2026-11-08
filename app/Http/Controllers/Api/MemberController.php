<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPage;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Support\Audit;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Model;
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

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewMembers', $workspace);

        return CursorPage::from($workspace->memberships()->with('user'), $request, function (Model $model): array {
            /** @var Membership $membership */
            $membership = $model;

            return $this->payload($membership);
        });
    }

    /**
     * Invites an address into the workspace. Every address gets the same answer and the same e-mail, whether
     * or not an account exists for it; only an address that is already a member is refused. Inviting the
     * same address again replaces the pending invitation and its link.
     *
     * @status 202
     */
    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('manageMembers', $workspace);

        // Addresses are stored lowercase; normalising first keeps the lookup case-insensitive on every database.
        if (is_string($email = $request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($email))]);
        }

        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
            // The language of the e-mail; the admin interface sends its own. The invitee's language is unknown
            // here (they may have no account), so the inviter's is the best guess.
            'locale' => ['sometimes', Rule::in(['en', 'hu'])],
        ]);
        $role = $request->enum('role', WorkspaceRole::class) ?? throw new LogicException('Validated role is missing.');
        $this->authorizeGrant($role);
        $email = $request->string('email')->toString();
        $locale = $request->string('locale', 'en')->toString();

        // One query of the same shape for every address: it never tells an unknown address from a known one.
        if ($workspace->members()->where('users.email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['The user is already a member of this workspace.'],
            ]);
        }

        // Re-inviting replaces the pending invitation, so replacing an owner's invitation needs the owner role too.
        $this->authorizeGrant(
            $workspace->invitations()->pending()->where('email', $email)->value('role') === WorkspaceRole::Owner
                ? WorkspaceRole::Owner
                : $role,
        );

        /** @var User $inviter */
        $inviter = $request->user();
        $invitation = WorkspaceInvitation::issue($workspace, $email, $role, $inviter, $locale);

        return response()->json(['data' => [
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]], 202);
    }

    public function update(Request $request, Workspace $workspace, User $user): JsonResponse
    {
        Gate::authorize('manageMembers', $workspace);

        $request->validate(['role' => ['required', Rule::enum(WorkspaceRole::class)]]);
        $role = $request->enum('role', WorkspaceRole::class) ?? throw new LogicException('Validated role is missing.');
        $membership = $this->membershipOf($workspace, $user);

        $this->authorizeGrant($role);
        $this->authorizeTouching($membership);

        $previous = $membership->role;

        $membership->guardLastOwner($role, function () use ($membership, $role, $previous, $user): void {
            $membership->update(['role' => $role]);

            if ($previous !== $role) {
                Audit::record('member.role_changed', $user, ['role' => [$previous->value, $role->value]]);
            }
        });

        return response()->json(['data' => $this->payload($membership->setRelation('user', $user))]);
    }

    public function destroy(Workspace $workspace, User $user): Response
    {
        Gate::authorize('manageMembers', $workspace);

        $membership = $this->membershipOf($workspace, $user);
        $this->authorizeTouching($membership);

        $membership->guardLastOwner(null, function () use ($membership, $user): void {
            $membership->delete();
            Audit::record('member.removed', $user, ['role' => [$membership->role->value, null]]);
        });

        return response()->noContent();
    }

    private function membershipOf(Workspace $workspace, User $user): Membership
    {
        return $workspace->memberships()->where('user_id', $user->id)->firstOrFail();
    }

    /**
     * Only an owner may hand out the owner role, by a role change or an invitation.
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
