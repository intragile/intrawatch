<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use App\Mail\WorkspaceInvitationMail;
use App\Models\Concerns\BelongsToWorkspace;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Database\Factories\WorkspaceInvitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * An invitation of an e-mail address into a workspace. The address is stored lowercase and only the SHA-256
 * of the token is kept. A workspace has at most one row per address; it is pending until accepted or expired.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $email
 * @property WorkspaceRole $role
 * @property string $token_hash
 * @property int|null $invited_by
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $inviter
 */
class WorkspaceInvitation extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<WorkspaceInvitationFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
        ];
    }

    /**
     * Creates or refreshes the invitation of the address and queues its e-mail. A refresh issues a new
     * token, role, inviter and expiry, so the earlier link stops working.
     *
     * The link carries the token in the URL fragment: browsers never send the fragment, so the token
     * stays out of proxy and server access logs and the Referer header. The SPA reads it from the hash.
     */
    public static function issue(Workspace $workspace, string $email, WorkspaceRole $role, User $inviter): self
    {
        $token = Str::random(64);
        $days = max(1, config()->integer('intrawatch.workspaces.invitations.expire_days', 7));

        $invitation = DB::transaction(function () use ($workspace, $email, $role, $inviter, $token, $days): self {
            $invitation = self::query()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'email' => $email],
                [
                    'role' => $role,
                    'token_hash' => self::hashToken($token),
                    'invited_by' => $inviter->id,
                    'expires_at' => CarbonImmutable::now()->addDays($days),
                    'accepted_at' => null,
                ],
            );
            Audit::record('member.invited', $invitation, ['email' => [null, $email], 'role' => [null, $role->value]], null, $workspace);

            Mail::to($email)->queue(new WorkspaceInvitationMail(
                $workspace->name,
                $inviter->name,
                $role->value,
                rtrim(config()->string('app.url'), '/').'/admin/invitations#token='.$token,
                $days,
            ));

            return $invitation;
        });

        return $invitation;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Invitations that can still be accepted.
     *
     * @param  Builder<static>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')->where('expires_at', '>', CarbonImmutable::now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at->lessThanOrEqualTo(CarbonImmutable::now());
    }

    /**
     * Whether the user may accept this invitation: it is pending, addressed to the user's e-mail, and its
     * inviter may still grant the role (an admin or owner, an owner for the owner role), so removing or
     * demoting a member also ends the invitations they sent.
     */
    public function isUsableBy(User $user, Workspace $workspace): bool
    {
        return $this->accepted_at === null
            && ! $this->isExpired()
            && hash_equals($this->email, mb_strtolower(trim($user->email)))
            && $this->inviterMayStillGrant($workspace);
    }

    private function inviterMayStillGrant(Workspace $workspace): bool
    {
        $role = $this->inviter?->roleIn($workspace);

        return match ($role) {
            WorkspaceRole::Owner => true,
            WorkspaceRole::Admin => $this->role !== WorkspaceRole::Owner,
            default => false,
        };
    }
}
