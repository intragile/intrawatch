<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Writes entries of the append-only audit trail.
 */
final class Audit
{
    /**
     * Field names containing any of these fragments never reach the trail.
     */
    private const SENSITIVE = ['password', 'token', 'secret', 'two_factor', 'remember'];

    /**
     * Record an event for the current request: the actor comes from the authenticated caller (a user, an
     * API token or the system) and the workspace from the subject or the resolved workspace.
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes  Field => [old, new].
     * @param  User|null  $actor  Overrides the authenticated caller.
     * @param  Workspace|null  $workspace  Overrides the workspace, for callers outside a workspace request.
     */
    public static function record(string $action, ?Model $subject = null, array $changes = [], ?User $actor = null, ?Workspace $workspace = null): AuditEvent
    {
        $workspaceId = $workspace !== null ? $workspace->id : self::workspaceId($subject);

        return self::write($action, $workspaceId, $subject, $changes, $actor);
    }

    /**
     * Record an event that belongs to no workspace, such as a login.
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     */
    public static function recordGlobal(string $action, ?User $actor = null, array $changes = []): AuditEvent
    {
        return self::write($action, null, null, $changes, $actor);
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     */
    private static function write(string $action, ?int $workspaceId, ?Model $subject, array $changes, ?User $actor): AuditEvent
    {
        [$actorType, $actorId] = self::actor($actor);
        $changes = self::sanitize($changes);

        return AuditEvent::create([
            'workspace_id' => $workspaceId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subject !== null ? Str::snake(class_basename($subject)) : null,
            'subject_id' => $subject !== null && is_scalar($subject->getKey()) ? (string) $subject->getKey() : null,
            'changes' => $changes === [] ? null : $changes,
            'ip' => request()->ip(),
        ]);
    }

    private static function workspaceId(?Model $subject): ?int
    {
        if ($subject instanceof Workspace) {
            return $subject->id;
        }

        $current = app(CurrentWorkspace::class);

        return $current->isResolved() ? $current->get()->id : null;
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private static function actor(?User $override): array
    {
        $user = $override ?? request()->user();

        if (! $user instanceof User) {
            return ['system', null];
        }

        if ($override === null && $user->authenticatedByToken()) {
            $token = $user->currentAccessToken();

            return ['token', is_numeric($token->getKey()) ? (int) $token->getKey() : null];
        }

        return ['user', $user->id];
    }

    /**
     * Defence in depth: drop every field whose name suggests a credential.
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private static function sanitize(array $changes): array
    {
        return array_filter(
            $changes,
            static fn (string $field): bool => ! Str::contains(Str::lower($field), self::SENSITIVE),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
