<?php

namespace App\Http\Controllers\Status;

use App\Http\Middleware\ResolveStatusHost;
use App\Models\StatusPage;
use App\Status\Pages\CachedPage;
use App\Status\Pages\PageCache;
use App\Status\Pages\PageResolver;
use App\Status\Pages\PageUnavailable;
use App\Status\Pages\PageView;
use App\Status\Pages\StatusPageSnapshot;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use PDOException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves the page behind a public request (by slug, or by the custom domain the host middleware matched) and
 * serves one cached rendering of it, with the response headers shared by the machine-readable endpoints.
 */
final class PublicPageGateway
{
    public const CACHE_CONTROL = 'public, max-age=30, stale-while-revalidate=60, stale-if-error=86400';

    public function __construct(
        private readonly PageResolver $resolver,
        private readonly PageCache $cache,
    ) {}

    /**
     * @param  Closure(StatusPage, PageView): string  $render  Renders the page model and its view to the cached body.
     * @param  Closure(CachedPage): Response  $respond
     */
    public function serve(Request $request, ?string $slug, string $variant, Closure $render, Closure $respond): Response
    {
        if ($slug !== null) {
            try {
                $page = $this->resolver->bySlug($slug);
            } catch (QueryException|PDOException) {
                return self::unavailable();
            }
            if ($page === null) {
                throw new NotFoundHttpException;
            }
            [$pageId, $workspaceId] = [$page['id'], $page['workspace_id']];
        } else {
            $pageId = $request->attributes->get(ResolveStatusHost::PAGE_ATTRIBUTE);
            $workspaceId = $request->attributes->get(ResolveStatusHost::WORKSPACE_ATTRIBUTE);
            if (! is_int($pageId) || ! is_int($workspaceId)) {
                throw new NotFoundHttpException;
            }
        }

        $model = null;
        $build = function () use ($pageId, &$model): PageView {
            $model = StatusPage::query()->with('workspace')->whereKey($pageId)->where('published', true)->first();
            if ($model === null) {
                throw new NotFoundHttpException;
            }

            return (new StatusPageSnapshot)->build($model, CarbonImmutable::now());
        };
        $view = function (PageView $view) use (&$model, $render): string {
            assert($model instanceof StatusPage);

            return $render($model, $view);
        };

        try {
            $cached = $this->cache->render($pageId, $workspaceId, $build, $view, $variant);
        } catch (PageUnavailable) {
            return self::unavailable();
        }

        return $respond($cached);
    }

    /**
     * A cacheable body with a strong entity tag, answered with 304 to a matching `If-None-Match`.
     *
     * @param  array<string, string>  $headers
     */
    public static function body(Request $request, string $body, string $etag, array $headers, bool $stale = false): Response
    {
        $response = new Response($body, Response::HTTP_OK, $headers + [
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ]);
        if ($stale) {
            $response->headers->set('X-Status-Stale', '1');
        }
        $response->isNotModified($request);

        return $response;
    }

    private static function unavailable(): Response
    {
        return new Response('Service Unavailable', Response::HTTP_SERVICE_UNAVAILABLE, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Retry-After' => '30',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
