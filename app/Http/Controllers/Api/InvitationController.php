<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPage;
use App\Http\Resources\WorkspaceAbilities;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Support\Audit;
use App\Support\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Pending workspace invitations (sent through `POST /workspaces/{workspace}/members`) and their acceptance.
 */
class InvitationController extends Controller
{
    private const INVALID = 'The invitation is invalid or has expired.';

    /**
     * Invitations not accepted yet, expired ones included (flagged), newest first.
     *
     * @response array{data: list<array{id: int, email: string, role: \App\Enums\WorkspaceRole, invited_by: array{id: int, name: string}|null, expires_at: string, expired: bool, created_at: string|null}>, meta: array{next_cursor: string|null, limit: int}}
     */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('manageMembers', $workspace);

        $query = $workspace->invitations()->whereNull('accepted_at')->with('inviter:id,name')->orderByDesc('id');

        return CursorPage::from($query, $request, function (Model $model): array {
            /** @var WorkspaceInvitation $invitation */
            $invitation = $model;
            $inviter = $invitation->inviter;

            return [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'invited_by' => $inviter === null ? null : ['id' => $inviter->id, 'name' => $inviter->name],
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'expired' => $invitation->isExpired(),
                'created_at' => $invitation->created_at?->toIso8601String(),
            ];
        });
    }

    /**
     * Revokes an invitation; its link stops working at once. Only an owner revokes an owner invitation.
     */
    public function destroy(Workspace $workspace, WorkspaceInvitation $invitation): Response
    {
        Gate::authorize('manageMembers', $workspace);
        abort_if(
            $invitation->role === WorkspaceRole::Owner && app(CurrentWorkspace::class)->role() !== WorkspaceRole::Owner,
            403,
            'Only an owner can revoke an owner invitation.',
        );

        DB::transaction(function () use ($invitation): void {
            $invitation->delete();
            Audit::record('member.invitation_revoked', $invitation, [
                'email' => [$invitation->email, null],
                'role' => [$invitation->role->value, null],
            ]);
        });

        return response()->noContent();
    }

    /**
     * Accepts an invitation addressed to the signed-in user's e-mail. Unknown, expired, revoked, already
     * accepted and foreign invitations, and those whose inviter can no longer grant the role, all fail with
     * the same 422.
     *
     * @response array{data: array{id: string, name: string, slug: string, timezone: string, role: \App\Enums\WorkspaceRole, channel: string|null, can: array{view: bool, update: bool, delete: bool, viewMembers: bool, manageMembers: bool, viewAudit: bool, viewMonitors: bool, manageMonitors: bool, viewNotifications: bool, manageNotifications: bool, viewIncidents: bool, manageIncidents: bool, viewStatusPages: bool, manageStatusPages: bool, manageCustomDomains: bool}}}
     */
    public function accept(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_if($user->authenticatedByToken(), 403, 'API tokens cannot accept invitations.');
        $request->validate(['token' => ['required', 'string']]);
        $token = $request->string('token')->toString();
        if (preg_match('/^[A-Za-z0-9]{64}$/', $token) !== 1) {
            throw self::invalid();
        }

        try {
            [$workspace, $role] = DB::transaction(function () use ($user, $token): array {
                $invitation = WorkspaceInvitation::query()
                    ->where('token_hash', WorkspaceInvitation::hashToken($token))
                    ->lockForUpdate()
                    ->first();
                if ($invitation === null) {
                    throw self::invalid();
                }
                $workspace = Workspace::query()->findOrFail($invitation->workspace_id);
                if (! $invitation->isUsableBy($user, $workspace) || $user->roleIn($workspace) !== null) {
                    throw self::invalid();
                }

                $workspace->memberships()->create(['user_id' => $user->id, 'role' => $invitation->role]);
                // Only one of two concurrent accepts may claim the invitation.
                $claimed = WorkspaceInvitation::query()
                    ->whereKey($invitation->id)
                    ->whereNull('accepted_at')
                    ->update(['accepted_at' => CarbonImmutable::now()]);
                if ($claimed !== 1) {
                    throw self::invalid();
                }
                Audit::record('member.added', $user, ['role' => [null, $invitation->role->value]], $user, $workspace);

                return [$workspace, $invitation->role];
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request made the user a member first.
            throw self::invalid();
        }

        return response()->json(['data' => [
            'id' => $workspace->public_id,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'timezone' => $workspace->timezone,
            'role' => $role->value,
            'channel' => WorkspaceAbilities::channel($user, $workspace),
            'can' => WorkspaceAbilities::for($user, $workspace),
        ]]);
    }

    private static function invalid(): ValidationException
    {
        return ValidationException::withMessages(['token' => [self::INVALID]]);
    }
}
