<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ends every way a user is signed in: the remember cookie, the database sessions and the API tokens.
 * Whoever knew the old password may hold any of them, so none may outlive a password reset. Both
 * reset paths (the e-mail reset and the CLI recovery command) go through here so they cannot drift
 * apart. It opens no transaction of its own and writes no audit event; callers do both, because
 * the e-mail and the CLI reset record different actors.
 */
final class SignOutEverywhere
{
    public function __invoke(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        if (config('session.driver') === 'database') {
            $connection = config('session.connection');
            DB::connection(is_string($connection) && $connection !== '' ? $connection : null)
                ->table(config()->string('session.table'))
                ->where('user_id', $user->id)
                ->delete();
        }

        $user->tokens()->delete();
    }
}
