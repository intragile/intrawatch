<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class OpenApiController extends Controller
{
    /**
     * The committed document (`docs/openapi.json`, exported by `scramble:export` and checked in CI) is
     * served as is: the endpoint is public, so it must not analyse the code base on every request.
     */
    public const DOCUMENT = 'docs/openapi.json';

    /**
     * The OpenAPI document describing this API.
     *
     * @hidden
     */
    public function __invoke(): Response
    {
        $path = base_path(self::DOCUMENT);
        $document = is_file($path) ? file_get_contents($path) : false;

        abort_if($document === false, 404, 'The OpenAPI document is not available in this build.');

        return response($document, 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
