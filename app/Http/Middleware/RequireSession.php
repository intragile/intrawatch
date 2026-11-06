<?php

namespace App\Http\Middleware;

use App\Exceptions\SessionUnavailable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects requests to session-establishing endpoints (login, setup) that carry no session, instead of
 * answering with a success whose login is lost.
 */
class RequireSession
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            throw SessionUnavailable::make();
        }

        return $next($request);
    }
}
