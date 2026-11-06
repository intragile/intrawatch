<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires every listed ability on a personal access token. Session (cookie) requests are not
 * token-authenticated, so they pass and are limited by role policies only.
 */
class EnsureTokenAbility
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        if ($user->authenticatedByToken()) {
            foreach ($abilities as $ability) {
                abort_unless($user->tokenCan($ability), 403, 'The API token lacks the required ability.');
            }
        }

        return $next($request);
    }
}
