<?php

namespace App\Http\Controllers;

use App\Support\ImportUploadLimit;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The admin single-page application shell. Every client-side route under /admin/ answers with the built
 * index.html, carrying the runtime configuration the browser needs before its first API call.
 */
final class SpaController
{
    public const CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self' ws: wss:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

    public function __invoke(?string $path = null): Response
    {
        // The asset directory and unknown files below it are missing assets, not client-side routes.
        if ($path === 'assets' || str_starts_with($path ?? '', 'assets/')) {
            throw new NotFoundHttpException;
        }

        $file = rtrim(config()->string('intrawatch.spa_path'), '/').'/index.html';
        $html = is_file($file) ? file_get_contents($file) : false;
        if ($html === false) {
            return new JsonResponse([
                'type' => 'urn:problem:admin-not-built',
                'title' => 'Service Unavailable',
                'status' => Response::HTTP_SERVICE_UNAVAILABLE,
                'detail' => 'The admin interface has not been built.',
            ], Response::HTTP_SERVICE_UNAVAILABLE, [
                'Content-Type' => 'application/problem+json',
                'Cache-Control' => 'no-store',
            ]);
        }

        $meta = '<meta name="intrawatch-config" content="'.htmlspecialchars($this->config(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">';
        $position = stripos($html, '</head>');
        $html = $position === false ? $meta.$html : substr_replace($html, $meta, $position, 0);

        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => self::CSP,
        ]);
    }

    private function config(): string
    {
        $key = config('broadcasting.connections.reverb.key');

        return json_encode([
            'version' => config()->string('app.version', 'dev'),
            'reverb' => ['key' => is_string($key) ? $key : ''],
            'locale' => config()->string('app.locale', 'en'),
            'import' => ['max_upload_mb' => app(ImportUploadLimit::class)->megabytes()],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
