<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteConventionTest extends TestCase
{
    public function test_all_api_routes_are_versioned(): void
    {
        $allowed = ['up', 'sanctum/csrf-cookie'];
        $unversioned = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            // Public status pages are HTML for browsers, not API.
            if (str_starts_with($route->getActionName(), 'App\\Http\\Controllers\\Status\\')) {
                continue;
            }
            // The admin single-page application shell and its assets are HTML, not API.
            if (in_array($uri, ['admin/{path?}', 'admin/assets/{file}'], true)) {
                continue;
            }
            if (in_array($uri, $allowed, true) || str_starts_with($uri, 'broadcasting/') || str_starts_with($uri, 'api/v1/')) {
                continue;
            }
            $unversioned[] = $uri;
        }

        $this->assertSame([], $unversioned, 'Routes outside the api/v1/ prefix.');
    }
}
