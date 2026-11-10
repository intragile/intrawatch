<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads and ends a user's rows in the database session table. With any other session driver there are
 * no rows to read; `SignOutOtherSessions` still ends the other sessions through the password hash that
 * Sanctum's AuthenticateSession middleware keeps in each of them.
 */
final class UserSessions
{
    public static function stored(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * The user's sessions that have not yet expired, newest activity first.
     *
     * @return list<object{id: string, ip_address: string|null, user_agent: string|null, last_activity: int}>
     */
    public static function active(User $user): array
    {
        if (! self::stored()) {
            return [];
        }

        $since = time() - config()->integer('session.lifetime', 120) * 60;

        /** @var list<object{id: string, ip_address: string|null, user_agent: string|null, last_activity: int}> $rows */
        $rows = self::table()
            ->where('user_id', $user->id)
            ->where('last_activity', '>=', $since)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->all();

        return $rows;
    }

    /**
     * Deletes every stored session of the user except the one with the given id.
     *
     * @return int The number of sessions deleted.
     */
    public static function deleteOthers(User $user, ?string $currentId): int
    {
        if (! self::stored()) {
            return 0;
        }

        return self::table()
            ->where('user_id', $user->id)
            ->when($currentId !== null, fn (Builder $query) => $query->where('id', '!=', $currentId))
            ->delete();
    }

    /**
     * The user's unexpired stored session whose public handle is the given one, or null. The handle is
     * recomputed for each of the user's own rows, so a raw session id or another user's handle never
     * matches.
     *
     * @return object{id: string, ip_address: string|null, user_agent: string|null, last_activity: int}|null
     */
    public static function findByPublicId(User $user, string $publicId): ?object
    {
        foreach (self::active($user) as $row) {
            if (hash_equals(self::publicId($row->id), $publicId)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Deletes one stored session of the user.
     */
    public static function delete(User $user, string $sessionId): int
    {
        if (! self::stored()) {
            return 0;
        }

        return self::table()->where('user_id', $user->id)->where('id', $sessionId)->delete();
    }

    /**
     * An opaque, stable handle for a session: the raw id is the session cookie value and never leaves
     * the server.
     */
    public static function publicId(string $sessionId): string
    {
        return substr(hash_hmac('sha256', 'session:'.$sessionId, config()->string('app.key')), 0, 32);
    }

    private static function table(): Builder
    {
        $connection = config('session.connection');

        return DB::connection(is_string($connection) && $connection !== '' ? $connection : null)
            ->table(config()->string('session.table'));
    }
}
