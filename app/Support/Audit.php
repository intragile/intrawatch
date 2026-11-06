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
     * Fields that carry only credential names, never values, and so may be recorded.
     */
    private const SAFE_FIELDS = ['secret_keys'];

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
        [$actorType, $actorId, $actorUserId] = self::actor($actor);
        $changes = self::sanitize($changes);

        return AuditEvent::create([
            'workspace_id' => $workspaceId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_user_id' => $actorUserId,
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
     * The actor type, the actor id (user or token id) and the id of the human behind it.
     *
     * @return array{0: string, 1: int|null, 2: int|null}
     */
    private static function actor(?User $override): array
    {
        $user = $override ?? request()->user();

        if (! $user instanceof User) {
            return ['system', null, null];
        }

        if ($override === null && $user->authenticatedByToken()) {
            $token = $user->currentAccessToken();

            return ['token', is_numeric($token->getKey()) ? (int) $token->getKey() : null, $user->id];
        }

        return ['user', $user->id, $user->id];
    }

    /**
     * Defence in depth: drop every field whose name suggests a credential, and every such key at any depth
     * inside the old and new values.
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private static function sanitize(array $changes): array
    {
        $clean = [];
        foreach ($changes as $field => [$old, $new]) {
            if (in_array($field, self::SAFE_FIELDS, true) || ! self::isSensitive($field)) {
                $clean[$field] = [self::scrub($old), self::scrub($new)];
            }
        }

        return $clean;
    }

    private static function scrub(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $clean = [];
        foreach ($value as $key => $item) {
            if (is_int($key) || ! self::isSensitive($key)) {
                $clean[$key] = self::scrub($item);
            }
        }

        return $clean;
    }

    private static function isSensitive(string $key): bool
    {
        return Str::contains(Str::lower($key), self::SENSITIVE);
    }
}
