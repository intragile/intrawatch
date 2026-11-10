<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Images of the user manual (screenshots), shipped with the admin build from spa/public/manual/{locale}/ and
 * referenced by the chapters as /admin/manual/{locale}/{file}. The names carry no content hash, so browsers
 * revalidate them instead of caching them for good: a new release may replace an image under the same name.
 */
final class SpaManualImageController
{
    private const TYPES = [
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function __invoke(string $locale, string $file): BinaryFileResponse
    {
        $type = self::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
        // The route admits single path segments only; the base name checks guard any other caller.
        if ($type === null || $file !== basename($file) || str_starts_with($file, '.') || $locale !== basename($locale)) {
            throw new NotFoundHttpException;
        }

        $path = rtrim(config()->string('intrawatch.spa_path'), '/').'/manual/'.$locale.'/'.$file;
        if (! is_file($path)) {
            throw new NotFoundHttpException;
        }

        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
        ], public: true, autoEtag: false, autoLastModified: true);
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
