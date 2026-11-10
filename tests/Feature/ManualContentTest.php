<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ManualContentTest extends TestCase
{
    /** A Markdown image: `![alt](src)`. */
    private const IMAGE = '/!\[([^\]]*)\]\(([^)\s]*)\)/';

    /** Manual images are served from the admin build, which copies spa/public to its root (/admin/). */
    private const IMAGE_PREFIX = '/admin/manual/';

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
            // Images are checked by test_images_exist_with_alt_text_in_both_languages.
            $prose = (string) preg_replace(self::IMAGE, '', $prose);

            $this->assertDoesNotMatchRegularExpression('/\]\([^)]*\)|<https?:\/\//', $prose, $file.': use a [[manual:]], [[route:]] or [[link:]] token instead of a Markdown link.');
        }
    }

    public function test_images_exist_with_alt_text_in_both_languages(): void
    {
        /** @var array<string, list<string>> $names image file names per locale */
        $names = [];
        foreach (glob(resource_path('manual/*/*/*.md')) ?: [] as $file) {
            $locale = basename(dirname($file, 2));
            $names[$locale] ??= [];
            $markdown = (string) file_get_contents($file);
            // An image inside a code example is not rendered as one.
            $markdown = (string) preg_replace('/^(```|~~~).*?^\1[^\n]*$/ms', '', $markdown);
            preg_match_all(self::IMAGE, $markdown, $images, PREG_SET_ORDER);

            foreach ($images as [$markup, $alt, $src]) {
                $this->assertNotSame('', trim($alt), $file.': '.$markup.' needs an alt text describing what it shows.');
                $this->assertStringStartsWith(self::IMAGE_PREFIX.$locale.'/', $src, $file.': '.$markup.' must point to '.self::IMAGE_PREFIX.$locale.'/.');
                $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+\.(png|webp)$/', basename($src), $file.': '.$src.' must be a .png or .webp file the admin serves.');
                $path = base_path('spa/public/manual/'.substr($src, strlen(self::IMAGE_PREFIX)));
                $this->assertFileExists($path, $file.': '.$src.' has no file under spa/public/manual.');
                $names[$locale][] = basename($src);
            }
        }

        $this->assertArrayHasKey('hu', $names);
        $this->assertArrayHasKey('en', $names);
        $this->assertNotEmpty($names['en'], 'The manual has no images; the check would pass vacuously.');
        $hu = array_values(array_unique($names['hu']));
        $en = array_values(array_unique($names['en']));
        sort($hu);
        sort($en);
        $this->assertSame($en, $hu, 'The Hungarian and the English manual must use the same images.');
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
