<?php

namespace App\Http\Middleware;

use App\Status\Pages\PageResolver;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use PDOException;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a published page's custom domain. A request whose host is the custom domain of a published page is
 * tagged with that page and may only reach the public status paths; anything else on that host is a plain
 * 404, so the admin, the API and session endpoints are never reachable through a status domain. Every other
 * host passes through untouched.
 */
final class ResolveStatusHost
{
    public const PAGE_ATTRIBUTE = 'status_page_id';

    public const WORKSPACE_ATTRIBUTE = 'status_workspace_id';

    private const PATHS = ['/', '/feed.atom', '/feed.rss', '/summary.json', '/badge.svg', '/up'];

    private const BADGE_PATH = '#^/badge/[0-9]+/(status|uptime|latency)\.svg$#';

    public function __construct(private readonly PageResolver $resolver) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = $this->candidate($request);
        if ($host === null) {
            return $next($request);
        }

        try {
            $page = $this->resolver->byDomain($host);
        } catch (QueryException|PDOException) {
            // Nothing is known about the host and the database is down: treat it as an ordinary host.
            $page = null;
        }
        if ($page === null) {
            return $next($request);
        }

        $path = $request->getPathInfo();
        if (! in_array($path, self::PATHS, true) && preg_match(self::BADGE_PATH, $path) !== 1) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store',
            ]);
        }

        $request->attributes->set(self::PAGE_ATTRIBUTE, $page['id']);
        $request->attributes->set(self::WORKSPACE_ATTRIBUTE, $page['workspace_id']);

        return $next($request);
    }

    /**
     * The normalised host when it could be a custom domain: a name with at least two labels that is neither an
     * IP address nor the installation's own host.
     */
    private function candidate(Request $request): ?string
    {
        try {
            $host = PageResolver::normalizeHost($request->getHost());
        } catch (SuspiciousOperationException) {
            return null;
        }
        if ($host === '' || ! str_contains($host, '.') || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        $own = parse_url(config()->string('app.url'), PHP_URL_HOST);
        if (is_string($own) && PageResolver::normalizeHost($own) === $host) {
            return null;
        }

        return $host;
    }
}
