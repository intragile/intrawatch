<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Fingerprinted build assets of the admin single-page application. File names carry a content hash, so they
 * are cached for a year without revalidation.
 */
final class SpaAssetController
{
    private const TYPES = [
        'js' => 'text/javascript; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'woff2' => 'font/woff2',
        'png' => 'image/png',
        'ico' => 'image/x-icon',
        'map' => 'application/json',
    ];

    public function __invoke(string $file): BinaryFileResponse
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $type = self::TYPES[$extension] ?? null;
        if ($type === null || ($extension === 'map' && app()->isProduction())) {
            throw new NotFoundHttpException;
        }

        // The route admits only a single path segment without separators; the base name check guards any other caller.
        if ($file !== basename($file) || str_starts_with($file, '.')) {
            throw new NotFoundHttpException;
        }

        $path = rtrim(config()->string('intrawatch.spa_path'), '/').'/assets/'.$file;
        if (! is_file($path)) {
            throw new NotFoundHttpException;
        }

        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
        ], public: true, autoEtag: false, autoLastModified: true);
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->setImmutable();

        return $response;
    }
}
