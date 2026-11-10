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

    public function test_chapters_link_only_through_manual_tokens(): void
    {
        // The SPA routes [[manual:]], [[route:]] and [[link:]] links itself; a raw Markdown link would
        // leave the SPA (or reload it) and is not checked by manual:check.
        $files = glob(resource_path('manual/*/*/*.md')) ?: [];
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $markdown = (string) file_get_contents($file);
            $prose = (string) preg_replace('/^(```|~~~).*?^\1[^\n]*$/ms', '', $markdown);
            $prose = (string) preg_replace('/`[^`\n]*`/', '', $prose);

            $this->assertDoesNotMatchRegularExpression('/\]\([^)]*\)|<https?:\/\//', $prose, $file.': use a [[manual:]], [[route:]] or [[link:]] token instead of a Markdown link.');
        }
    }

    public function test_known_routes_match_spa_router(): void
    {
        $router = file_get_contents(base_path('spa/src/router/index.ts'));
        $this->assertIsString($router);

        // Every quoted `name:` counts, whatever its quoting or position; `redirect: { name: ... }`
        // targets are named on their own record too, so de-duplicating covers them.
        preg_match_all('/\bname:\s*[\'"]([^\'"]+)[\'"]/', $router, $matches);
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
