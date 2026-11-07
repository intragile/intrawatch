<?php

namespace App\Http\Controllers\Status;

use App\Models\StatusPage;
use App\Status\Pages\CachedPage;
use App\Status\Pages\PageRenderer;
use App\Status\Pages\PageView;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public HTML status page, served from the page cache.
 */
final class StatusPageController
{
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
        private readonly PublicPageGateway $gateway,
        private readonly PageRenderer $renderer,
    ) {}

    public function show(Request $request, string $slug): Response
    {
        return $this->serve($request, $slug);
    }

    /**
     * The root of a custom domain; nothing on any other host.
     */
    public function home(Request $request): Response
    {
        return $this->serve($request, null);
    }

    private function serve(Request $request, ?string $slug): Response
    {
        return $this->gateway->serve(
            $request,
            $slug,
            'html',
            fn (StatusPage $page, PageView $view): string => $this->renderer->render($page, $view),
            static fn (CachedPage $cached): Response => PublicPageGateway::body($request, $cached->html, $cached->etag, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => PublicPageGateway::CACHE_CONTROL,
                'Content-Security-Policy' => self::CSP,
                'Referrer-Policy' => 'strict-origin-when-cross-origin',
            ], $cached->stale),
            static fn (): Response => new Response(self::UNAVAILABLE, Response::HTTP_SERVICE_UNAVAILABLE, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Retry-After' => '30',
                'Content-Security-Policy' => self::CSP,
            ] + PublicPageGateway::ERROR_HEADERS),
        );
    }
}
