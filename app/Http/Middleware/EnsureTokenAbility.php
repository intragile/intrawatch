<?php

namespace App\Http\Middleware;

use App\Enums\TokenAbility;
use App\Models\User;
use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires every listed ability on a personal access token, and a current workspace role that still
 * allows each ability, so a token never outlives a demotion of its creator. Session (cookie) requests
 * are not token-authenticated, so they pass and are limited by role policies only.
 *
 * Ability names are validated against TokenAbility on every request, so a misspelt name in a route
 * definition fails loudly instead of silently denying (or allowing) access. Token requests must run
 * after the `workspace` middleware.
 */
class EnsureTokenAbility
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        if ($abilities === []) {
            throw new LogicException('The ability middleware needs at least one ability.');
        }
        $required = array_map(static fn (string $ability): TokenAbility => TokenAbility::from($ability), $abilities);

        $user = $request->user();

        abort_unless($user instanceof User, 401);

        if ($user->authenticatedByToken()) {
            if (! $this->current->isResolved()) {
                throw new LogicException('Token abilities can only be checked inside a resolved workspace.');
            }

            foreach ($required as $ability) {
                abort_unless($user->tokenCan($ability->value), 403, 'The API token lacks the required ability.');
                abort_unless(
                    $this->current->role()->atLeast($ability->minimumRole()),
                    403,
                    'Your current role no longer allows this API token ability.',
                );
            }
        }

        return $next($request);
    }
}
