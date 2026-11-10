<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ManualContentTest extends TestCase
{
    public function test_manual_check_passes(): void
    {
        $exitCode = Artisan::call('manual:check');

        $this->assertSame(0, $exitCode, Artisan::output());
    }

    public function test_known_routes_match_spa_router(): void
    {
        $router = file_get_contents(base_path('spa/src/router/index.ts'));
        $this->assertIsString($router);

        // Route records name themselves on a line of their own; `redirect: { name: ... }` targets
        // are named elsewhere too, so de-duplicating covers multi-line redirect objects.
        preg_match_all("/^\s*name: '([^']+)',?$/m", $router, $matches);
        $spa = array_values(array_unique($matches[1]));
        sort($spa);

        $known = require resource_path('manual/_routes.php');
        $this->assertIsArray($known);
        sort($known);

        $this->assertNotEmpty($spa);
        $this->assertSame($spa, $known, 'resources/manual/_routes.php is out of date with spa/src/router/index.ts.');
        $this->assertSame($known, $this->sorted(config('manual.known_routes')), 'config/manual.php does not use resources/manual/_routes.php.');
    }

    /**
     * @return list<string>
     */
    private function sorted(mixed $routes): array
    {
        $this->assertIsArray($routes);
        $list = array_values(array_filter($routes, 'is_string'));
        sort($list);

        return $list;
    }
}
