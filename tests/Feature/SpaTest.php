<?php

namespace Tests\Feature;

use App\Models\StatusPage;
use App\Models\Workspace;
use App\Support\ImportUploadLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SpaTest extends TestCase
{
    use RefreshDatabase;

    private const CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self' ws: wss:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

    private string $dist;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://watch.example.com']);

        $this->dist = sys_get_temp_dir().'/intrawatch-spa-'.getmypid();
        File::deleteDirectory($this->dist);
        File::ensureDirectoryExists($this->dist.'/assets');
        File::put($this->dist.'/index.html', <<<'HTML'
            <!doctype html>
            <html lang="en">
            <head><meta charset="utf-8"><title>IntraWatch</title><script type="module" src="/admin/assets/app-abc123.js"></script></head>
            <body><div id="app"></div></body>
            </html>
            HTML);
        File::put($this->dist.'/assets/app-abc123.js', 'console.log("app");');
        File::put($this->dist.'/assets/app-abc123.css', 'body{margin:0}');
        File::put($this->dist.'/assets/app-abc123.js.map', '{}');
        File::put(dirname($this->dist).'/intrawatch-spa-secret.txt', 'secret');

        config([
            'intrawatch.spa_path' => $this->dist,
            'app.version' => '1.2.3',
            'app.locale' => 'en',
            'broadcasting.connections.reverb.key' => 'reverb-"key"',
            'intrawatch.import.max_upload_mb' => 128,
        ]);
        $this->phpUploadLimit(PHP_INT_MAX);
    }

    /**
     * Makes the shell see the given PHP upload limit (upload_max_filesize/post_max_size cannot change at run time).
     */
    private function phpUploadLimit(int $bytes): void
    {
        $this->app->instance(ImportUploadLimit::class, new class($bytes) extends ImportUploadLimit
        {
            public function __construct(private readonly int $phpLimit) {}

            public function phpMaxBytes(): int
            {
                return $this->phpLimit;
            }
        });
    }

    /**
     * @return array<mixed>
     */
    private function shellImportConfig(): array
    {
        $html = (string) $this->get('/admin/')->assertOk()->getContent();
        preg_match('/<meta name="intrawatch-config" content="([^"]*)"/', $html, $match);
        $content = $match[1] ?? null;
        $this->assertIsString($content, 'config meta tag');
        $config = json_decode(html_entity_decode($content, ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($config);
        $this->assertIsArray($config['import']);

        return $config['import'];
    }

    public function test_shell_reports_the_upload_limit_clamped_to_the_php_limit(): void
    {
        $this->phpUploadLimit(2 * 1024 * 1024);

        $this->assertSame(['max_upload_mb' => 2], $this->shellImportConfig());
    }

    public function test_shell_reports_a_php_limit_under_one_megabyte_as_a_fraction(): void
    {
        $this->phpUploadLimit(512 * 1024);

        $this->assertSame(['max_upload_mb' => 0.5], $this->shellImportConfig());
    }

    public function test_shell_reports_the_configured_limit_when_it_is_below_the_php_limit(): void
    {
        $this->phpUploadLimit(512 * 1024 * 1024);

        $this->assertSame(['max_upload_mb' => 128], $this->shellImportConfig());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dist);
        File::delete(dirname($this->dist).'/intrawatch-spa-secret.txt');
        parent::tearDown();
    }

    public function test_shell_is_served_for_deep_links_with_config_and_csp(): void
    {
        $expected = htmlspecialchars(
            (string) json_encode(['version' => '1.2.3', 'reverb' => ['key' => 'reverb-"key"'], 'locale' => 'en', 'import' => ['max_upload_mb' => 128]]),
            ENT_QUOTES,
        );

        foreach (['/admin/', '/admin', '/admin/monitors', '/admin/w/acme/monitors/12/edit', '/admin/assetsx'] as $path) {
            $response = $this->get($path);
            $response->assertOk()
                ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
                ->assertHeader('Content-Security-Policy', self::CSP)
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Referrer-Policy', 'same-origin')
                ->assertHeader('X-Frame-Options', 'DENY');
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), $path);

            $body = (string) $response->getContent();
            $this->assertStringContainsString(
                '<meta name="intrawatch-config" content="'.$expected.'"></head>',
                $body,
                $path,
            );
            $this->assertStringContainsString('<script type="module" src="/admin/assets/app-abc123.js">', $body);
        }
    }

    public function test_assets_are_immutable_and_traversal_is_404(): void
    {
        $js = $this->get('/admin/assets/app-abc123.js');
        $js->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        $this->assertStringStartsWith('text/javascript', (string) $js->headers->get('Content-Type'));
        $this->assertSame('console.log("app");', $js->streamedContent());

        $css = $this->get('/admin/assets/app-abc123.css')->assertOk();
        $this->assertStringStartsWith('text/css', (string) $css->headers->get('Content-Type'));

        // Source maps are served outside production only.
        $this->get('/admin/assets/app-abc123.js.map')->assertOk();
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->get('/admin/assets/app-abc123.js.map')->assertNotFound();

        foreach ([
            '/admin/assets/..%2F..%2F.env',
            '/admin/assets/..%2Fintrawatch-spa-secret.txt',
            '/admin/assets/../index.html',
            '/admin/assets/missing.js',
            '/admin/assets/app-abc123.txt',
            '/admin/assets/nested/app.js',
            '/admin/assets/',
            '/admin/assets',
        ] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_manual_images_are_served_with_revalidation_and_traversal_is_404(): void
    {
        File::ensureDirectoryExists($this->dist.'/manual/hu');
        File::put($this->dist.'/manual/hu/monitor-list.png', 'png-bytes');
        File::put($this->dist.'/manual/hu/monitor-list.webp', 'webp-bytes');
        File::put($this->dist.'/manual/hu/notes.txt', 'text');

        $png = $this->get('/admin/manual/hu/monitor-list.png');
        $png->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'max-age=3600, public')
            ->assertHeaderMissing('Set-Cookie');
        $this->assertSame('png-bytes', $png->streamedContent());
        $this->get('/admin/manual/hu/monitor-list.webp')->assertOk()->assertHeader('Content-Type', 'image/webp');

        foreach ([
            '/admin/manual/hu/missing.png',
            '/admin/manual/hu/notes.txt',
            '/admin/manual/en/monitor-list.png',
            '/admin/manual/hu/..%2F..%2Findex.html',
            '/admin/manual/..%2Fassets/app-abc123.js',
            '/admin/manual/hu/nested/monitor-list.png',
            '/admin/manual/hu',
            '/admin/manual',
        ] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_missing_build_is_problem_503(): void
    {
        config(['intrawatch.spa_path' => $this->dist.'/does-not-exist']);

        $this->get('/admin/monitors')
            ->assertStatus(503)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson(['type' => 'urn:problem:admin-not-built', 'status' => 503]);
        $this->get('/admin/assets/app-abc123.js')->assertNotFound();
    }

    public function test_root_redirects_to_admin_on_app_host(): void
    {
        $this->get('/')->assertRedirect('/admin/')->assertStatus(302);
        $this->get('http://watch.example.com/')->assertRedirect('/admin/');
        $this->get('http://192.168.1.10/')->assertRedirect('/admin/');
    }

    public function test_admin_is_404_on_custom_domain(): void
    {
        $workspace = Workspace::factory()->create();
        StatusPage::factory()->create([
            'workspace_id' => $workspace->id,
            'slug' => 'acme',
            'title' => 'Acme status',
            'published' => true,
            'custom_domain' => 'status.example.test',
        ]);

        $this->get('http://status.example.test/')->assertOk()->assertSee('Acme status');
        foreach (['/admin/', '/admin', '/admin/monitors', '/admin/assets/app-abc123.js', '/api/v1/ping'] as $path) {
            $this->get('http://status.example.test'.$path)->assertNotFound();
        }

        // The same paths on the application's own host.
        $this->get('http://watch.example.com/admin/')->assertOk();
        $this->get('http://watch.example.com/admin/assets/app-abc123.js')->assertOk();
    }

    public function test_shell_sets_no_cookie(): void
    {
        $this->get('/admin/monitors')->assertOk()->assertHeaderMissing('Set-Cookie');
        $this->get('/admin/assets/app-abc123.js')->assertOk()->assertHeaderMissing('Set-Cookie');
        $this->get('/')->assertHeaderMissing('Set-Cookie');
    }
}
