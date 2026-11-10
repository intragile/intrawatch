<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Ends every session of the user except the one making the request: the other database session rows,
 * the remember cookies of other browsers (a new remember token) and, through `logoutOtherDevices`, any
 * session the table does not hold, whose stored password hash no longer matches. The current browser
 * keeps its session and, if it had one, gets a fresh remember cookie. Like `SignOutEverywhere` it opens
 * no transaction and writes no audit event of its own.
 */
final class SignOutOtherSessions
{
    /**
     * @param  string  $password  The user's current (possibly just changed) password, in plain text.
     * @return int The number of stored sessions that were ended.
     */
    public function __invoke(User $user, Request $request, #[\SensitiveParameter] string $password): int
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        $ended = UserSessions::deleteOthers($user, $request->hasSession() ? $request->session()->getId() : null);

        $guard = Auth::guard('web');
        if ($guard->user() === $user) {
            $guard->logoutOtherDevices($password);
        }

        return $ended;
    }
}
