<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\CacheableGenerator;
use Dedoc\Scramble\Scramble;
use Illuminate\Http\JsonResponse;

class OpenApiController extends Controller
{
    /**
     * The OpenAPI document describing this API.
     *
     * @hidden
     */
    public function __invoke(CacheableGenerator $generator): JsonResponse
    {
        $config = Scramble::getGeneratorConfig('default');

        return response()->json($generator($config), options: JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
