<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Ends one other stored session of the user: its database session row and, because a remember token is
 * per user and not per browser, the remember cookies of every other browser (a new remember token). The
 * browser making the request keeps its session and, if it had a remember cookie, gets a fresh one, as in
 * `SignOutOtherSessions`. The other sessions that remain keep working until they expire; only their
 * remember cookies stop working. Without that rotation the ended browser could sign itself back in with
 * its remember cookie. Opens no transaction and writes no audit event of its own.
 */
final class SignOutSession
{
    public function __invoke(User $user, Request $request, string $sessionId): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        UserSessions::delete($user, $sessionId);

        $guard = Auth::guard('web');
        if ($guard->user() === $user) {
            // What `logoutOtherDevices` does for the current browser, without the password it needs:
            // re-issue the remember cookie it holds (or was just given) with the new token.
            \Closure::bind(function () use ($user): void {
                if ($this->recaller() !== null || $this->getCookieJar()->hasQueued($this->getRecallerName())) {
                    $this->queueRecallerCookie($user);
                }
            }, $guard, SessionGuard::class)();
        }
    }
}
