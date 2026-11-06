<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the {workspace} route parameter and exposes it, with the caller's role, as CurrentWorkspace.
 * Callers that are not members get a 404 so that workspace existence is not disclosed.
 */
class ResolveWorkspace
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = $request->route();
        $parameter = $route?->parameter('workspace');

        $workspace = $parameter instanceof Workspace
            ? $parameter
            : (is_string($parameter) ? Workspace::where('slug', $parameter)->first() : null);

        $role = $user instanceof User && $workspace !== null ? $user->roleIn($workspace) : null;

        if ($workspace === null || $role === null) {
            abort(404);
        }

        $route?->setParameter('workspace', $workspace);
        $this->current->set($workspace, $role);

        return $next($request);
    }
}
