<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\SignOutOtherSessions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Changes the signed-in user's password. Only the browser session may do it, never an API token.
 */
class PasswordController extends Controller
{
    public function __construct(private readonly SignOutOtherSessions $signOutOthers) {}

    /**
     * Change the password.
     *
     * Needs the current password. The browser that makes the change stays signed in; every other session
     * and remember cookie of the user ends, and all of the user's API tokens are revoked, as with a
     * password reset: whoever knew the old password may hold any of them. API tokens get a 403.
     */
    public function update(Request $request): Response
    {
        $user = self::sessionUser($request, 'API tokens cannot change the password.');

        $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            // The new password: at least 10 characters, as for a password reset, and not the current one.
            'password' => ['required', 'string', Password::default(), 'confirmed', 'different:current_password'],
        ]);

        $password = $request->string('password')->toString();

        DB::transaction(function () use ($user, $request, $password): void {
            $user->forceFill(['password' => Hash::make($password)])->save();
            $sessions = ($this->signOutOthers)($user, $request, $password);
            $keys = $user->tokens()->delete();

            // Counts only; the password itself is never part of the trail.
            Audit::recordGlobal('auth.password_changed', $user, [
                'other_sessions' => [$sessions, 0],
                'api_keys' => [$keys, 0],
            ]);
        });

        return response()->noContent();
    }

    /**
     * The user behind a browser session; API tokens are refused.
     */
    public static function sessionUser(Request $request, string $tokenMessage): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        abort_if($user->authenticatedByToken() || ! $request->hasSession(), 403, $tokenMessage);

        return $user;
    }
}
