<?php

namespace App\Http\Controllers\Status;

use App\Http\Middleware\ResolveStatusHost;
use App\Models\StatusPage;
use App\Status\Pages\CachedPage;
use App\Status\Pages\PageCache;
use App\Status\Pages\PageRenderer;
use App\Status\Pages\PageResolver;
use App\Status\Pages\PageUnavailable;
use App\Status\Pages\PageView;
use App\Status\Pages\StatusPageSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use PDOException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public HTML status page, served from the page cache.
 */
final class StatusPageController
{
    private const CACHE_CONTROL = 'public, max-age=30, stale-while-revalidate=60, stale-if-error=86400';

    private const CSP = "default-src 'none'; style-src 'unsafe-inline'; img-src https: data:; base-uri 'none'; form-action 'none'; frame-ancestors *";

    private const UNAVAILABLE = <<<'HTML'
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta http-equiv="refresh" content="30"><title>Status page unavailable</title>
        <style>body{margin:0;font:16px/1.5 system-ui,sans-serif;background:#f6f7f9;color:#1d2330}main{max-width:560px;margin:15vh auto;padding:0 16px;text-align:center}</style></head>
        <body><main><h1>Status page unavailable</h1><p>This status page is temporarily unavailable. It will reload automatically.</p></main></body>
        </html>
        HTML;

    public function __construct(
        private readonly PageResolver $resolver,
        private readonly PageCache $cache,
        private readonly PageRenderer $renderer,
    ) {}

    public function show(Request $request, string $slug): Response
    {
        try {
            $page = $this->resolver->bySlug($slug);
        } catch (QueryException|PDOException) {
            return $this->unavailable();
        }
        if ($page === null) {
            throw new NotFoundHttpException;
        }

        return $this->respond($request, $page['id'], $page['workspace_id']);
    }

    /**
     * The root of a custom domain; nothing on any other host.
     */
    public function home(Request $request): Response
    {
        $pageId = $request->attributes->get(ResolveStatusHost::PAGE_ATTRIBUTE);
        $workspaceId = $request->attributes->get(ResolveStatusHost::WORKSPACE_ATTRIBUTE);
        if (! is_int($pageId) || ! is_int($workspaceId)) {
            throw new NotFoundHttpException;
        }

        return $this->respond($request, $pageId, $workspaceId);
    }

    private function respond(Request $request, int $pageId, int $workspaceId): Response
    {
        $model = null;
        $build = function () use ($pageId, &$model): PageView {
            $model = StatusPage::query()->with('workspace')->whereKey($pageId)->where('published', true)->first();
            if ($model === null) {
                throw new NotFoundHttpException;
            }

            return (new StatusPageSnapshot)->build($model, CarbonImmutable::now());
        };
        $view = function (PageView $view) use (&$model): string {
            assert($model instanceof StatusPage);

            return $this->renderer->render($model, $view);
        };

        try {
            $cached = $this->cache->render($pageId, $workspaceId, $build, $view);
        } catch (PageUnavailable) {
            return $this->unavailable();
        }

        return $this->page($request, $cached);
    }

    private function page(Request $request, CachedPage $cached): Response
    {
        $response = new Response($cached->html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => self::CACHE_CONTROL,
            'ETag' => $cached->etag,
            'Content-Security-Policy' => self::CSP,
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ]);
        if ($cached->stale) {
            $response->headers->set('X-Status-Stale', '1');
        }
        // Answers a matching If-None-Match with 304, keeping the caching headers and dropping the body.
        $response->isNotModified($request);

        return $response;
    }

    private function unavailable(): Response
    {
        return new Response(self::UNAVAILABLE, Response::HTTP_SERVICE_UNAVAILABLE, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Retry-After' => '30',
            'Content-Security-Policy' => self::CSP,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
