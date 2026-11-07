<?php

namespace Tests\Feature\Status;

use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Events\MonitorStatusChanged;
use App\Models\CheckRollup;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\StatusPageItem;
use App\Models\StatusPageSection;
use App\Models\Workspace;
use App\Status\Pages\PageCache;
use App\Status\Pages\PageStamps;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicStatusPageTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    private Workspace $workspace;

    private StatusPage $page;

    private Monitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://watch.example.com']);
        $this->now = CarbonImmutable::parse('2026-11-07 12:30:00', 'UTC');
        $this->travelTo($this->now);

        $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Budapest']);
        $this->page = StatusPage::factory()->create([
            'workspace_id' => $this->workspace->id,
            'slug' => 'acme',
            'title' => 'Acme status',
            'published' => true,
        ]);
        $section = StatusPageSection::factory()->create(['status_page_id' => $this->page->id, 'name' => 'Core services']);
        $this->monitor = Monitor::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Website']);
        $this->setStatus(MonitorStatus::Up);
        StatusPageItem::factory()->create([
            'status_page_section_id' => $section->id,
            'monitor_id' => $this->monitor->id,
            'position' => 0,
        ]);
    }

    public function test_published_page_renders_and_unpublished_is_404(): void
    {
        $response = $this->get('/status/acme');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeader('Cache-Control', 'max-age=30, public, stale-if-error=86400, stale-while-revalidate=60')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src https: data:; base-uri 'none'; form-action 'none'; frame-ancestors *")
            ->assertHeaderMissing('Set-Cookie')
            ->assertHeaderMissing('X-Status-Stale')
            ->assertSee('Acme status')
            ->assertSee('Core services')
            ->assertSee('Website')
            ->assertSee('All systems operational')
            ->assertSee('<meta http-equiv="refresh" content="60">', false)
            ->assertSee('<link rel="canonical" href="https://watch.example.com/status/acme">', false)
            ->assertSee('Powered by IntraWatch');
        $this->assertStringNotContainsString('<script', (string) $response->getContent());
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{64}"$/', (string) $response->headers->get('ETag'));

        $missing = $this->get('/status/no-such-page');
        $missing->assertNotFound()->assertHeaderMissing('Set-Cookie');

        $this->page->update(['published' => false]);
        app(PageStamps::class)->forgetPage($this->page);
        $unpublished = $this->get('/status/acme');
        $unpublished->assertNotFound();
        $this->assertSame($missing->getContent(), $unpublished->getContent());
    }

    public function test_cache_hit_makes_no_queries(): void
    {
        $first = $this->get('/status/acme')->assertOk();
        $etag = (string) $first->headers->get('ETag');

        DB::enableQueryLog();
        DB::flushQueryLog();
        $second = $this->get('/status/acme')->assertOk();
        $this->assertSame([], DB::getQueryLog());
        $this->assertSame($etag, $second->headers->get('ETag'));
        $this->assertSame($first->getContent(), $second->getContent());

        $notModified = $this->get('/status/acme', ['If-None-Match' => $etag]);
        $notModified->assertStatus(304)
            ->assertHeader('ETag', $etag)
            ->assertHeader('Cache-Control', 'max-age=30, public, stale-if-error=86400, stale-while-revalidate=60');
        $this->assertSame('', $notModified->getContent());
        $this->assertSame([], DB::getQueryLog());

        $this->get('/status/acme', ['If-None-Match' => '"something-else"'])->assertOk();
    }

    public function test_status_change_invalidates_page(): void
    {
        $this->get('/status/acme')->assertOk()->assertSee('All systems operational');

        $this->setStatus(MonitorStatus::Down);
        event(new MonitorStatusChanged($this->workspace->id, $this->monitor->id, MonitorStatus::Up, MonitorStatus::Down, $this->now, $this->now, 'down'));

        $this->get('/status/acme')->assertOk()->assertSee('Major outage')->assertDontSee('All systems operational');
    }

    public function test_cached_page_expires_after_its_ttl(): void
    {
        $this->get('/status/acme')->assertOk()->assertSee('All systems operational');
        // A status write without an event: only the TTL brings it onto the page.
        $this->setStatus(MonitorStatus::Down);

        $this->travel(30)->seconds();
        $this->get('/status/acme')->assertSee('All systems operational');

        $this->travel(31)->seconds();
        $this->get('/status/acme')->assertSee('Major outage');
    }

    public function test_database_outage_serves_last_good_copy(): void
    {
        $warm = $this->get('/status/acme')->assertOk();
        $this->travel(2)->minutes();

        $this->withDeadDatabase(function () use ($warm): void {
            $response = $this->get('/status/acme');
            $response->assertOk()->assertHeader('X-Status-Stale', '1')->assertSee('Acme status');
            $this->assertSame($warm->getContent(), $response->getContent());
        });
    }

    public function test_database_outage_without_a_copy_is_a_static_503(): void
    {
        $this->get('/status/acme')->assertOk();
        Cache::store('status_pages')->forget(PageStamps::lastGoodKey($this->page->id));
        $this->travel(2)->minutes();

        $this->withDeadDatabase(function (): void {
            $response = $this->get('/status/acme');
            $response->assertStatus(503)->assertHeader('Retry-After', '30')->assertHeaderMissing('Set-Cookie');
            $this->assertStringContainsString('temporarily unavailable', (string) $response->getContent());
        });
    }

    public function test_database_outage_for_an_unresolved_slug_is_a_static_503(): void
    {
        $this->withDeadDatabase(function (): void {
            $this->get('/status/acme')->assertStatus(503)->assertHeader('Retry-After', '30');
        });
    }

    public function test_custom_domain_serves_only_status_routes(): void
    {
        $this->page->update(['custom_domain' => 'status.example.test']);
        app(PageStamps::class)->forgetPage($this->page);

        $this->get('http://status.example.test/')
            ->assertOk()
            ->assertSee('Acme status')
            ->assertSee('<link rel="canonical" href="https://status.example.test">', false)
            ->assertHeaderMissing('Set-Cookie');
        $this->get('http://Status.Example.TEST:8443/')->assertOk()->assertSee('Acme status');
        $this->get('/', ['Host' => 'status.example.test.'])->assertOk()->assertSee('Acme status');
        $this->get('http://status.example.test/up')->assertOk();

        foreach (['/api/v1/me', '/api/v1/setup', '/status/acme', '/sanctum/csrf-cookie', '/anything'] as $path) {
            $response = $this->get('http://status.example.test'.$path);
            $response->assertNotFound()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
            $this->assertSame('Not Found', $response->getContent(), $path);
        }

        // Any other host is the normal application.
        $this->get('http://other.test/status/acme')->assertOk()->assertSee('Acme status');
        $this->getJson('http://other.test/api/v1/setup')->assertOk();
        $this->get('http://other.test/')->assertNotFound();
        $this->get('http://192.168.1.10/status/acme')->assertOk();
    }

    public function test_unpublished_custom_domain_is_a_normal_host(): void
    {
        $this->page->update(['custom_domain' => 'status.example.test', 'published' => false]);
        app(PageStamps::class)->forgetPage($this->page);

        $this->get('http://status.example.test/')->assertNotFound();
        $this->getJson('http://status.example.test/api/v1/setup')->assertOk();
    }

    public function test_public_url_comes_from_configuration(): void
    {
        config(['app.url' => 'https://watch.example.com/']);
        $this->assertSame('https://watch.example.com/status/acme', $this->page->publicUrl());

        $this->page->custom_domain = 'status.example.test';
        $this->assertSame('https://status.example.test', $this->page->publicUrl());
    }

    public function test_forged_host_is_not_reflected(): void
    {
        $response = $this->get('http://evil.test/status/acme', ['X-Forwarded-Host' => 'evil2.test']);

        $response->assertOk()->assertSee('https://watch.example.com/status/acme', false);
        $this->assertStringNotContainsString('evil', (string) $response->getContent());
    }

    public function test_user_content_is_inert(): void
    {
        $this->page->update([
            'title' => 'Acme <script>alert("title")</script>',
            'description' => 'Hello <script>alert("description")</script>',
            'footer' => 'Footer <script>alert("footer")</script> [link](javascript:alert(1))',
            'custom_css' => 'body { color: red; } </style><script>alert(1)</script>',
            'accent_color' => '#336699',
        ]);
        app(PageStamps::class)->forgetPage($this->page);
        $incident = Incident::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Outage <script>alert("incident")</script>',
            'impact' => IncidentImpact::Major,
            'status' => IncidentStatus::Identified,
            'started_at' => $this->now->subHour(),
        ]);
        $incident->monitors()->attach($this->monitor->id);
        IncidentUpdate::factory()->create([
            'incident_id' => $incident->id,
            'status' => IncidentStatus::Identified,
            'message' => "We found it. <script>alert('update')</script> **bold**",
        ]);

        $response = $this->get('/status/acme')->assertOk();
        $html = (string) $response->getContent();

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('</style><script', $html);
        $this->assertStringContainsString('\3C /style>\3C script>alert(1)\3C /script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;incident&quot;)&lt;/script&gt;', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('--accent: #336699', $html);
        $this->assertSame(1, substr_count($html, '</style>'));
    }

    public function test_hungarian_locale(): void
    {
        $this->page->update(['locale' => 'hu']);
        app(PageStamps::class)->forgetPage($this->page);
        Incident::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Régi hiba',
            'impact' => IncidentImpact::Minor,
            'status' => IncidentStatus::Resolved,
            'started_at' => $this->now->subDays(2),
            'resolved_at' => $this->now->subDays(2)->addHour(),
        ])->monitors()->attach($this->monitor->id);
        DB::table('check_rollups')->insert([
            'monitor_id' => $this->monitor->id,
            'bucket' => CheckRollup::BUCKET_DAY,
            'bucket_start' => $this->now->setTimezone('Europe/Budapest')->startOfDay()->getTimestamp(),
            'total' => 1000,
            'up' => 999,
            'down' => 1,
            'degraded' => 0,
            'pending' => 0,
            'maintenance' => 0,
        ]);

        $response = $this->get('/status/acme')->assertOk();

        $response->assertSee('<html lang="hu">', false)
            ->assertSee('Minden rendszer működik')
            ->assertSee('Korábbi incidensek')
            ->assertSee('Megoldva')
            ->assertSee('Rendelkezésre állás: 99,90%')
            ->assertSee('2026. november 7. · Részleges kiesés · 99,90%')
            ->assertSee('2026. november 5.')
            ->assertDontSee('All systems operational');
        $this->assertSame('en', app()->getLocale());
    }

    public function test_concurrent_misses_render_once(): void
    {
        $warm = $this->get('/status/acme')->assertOk();
        $this->setStatus(MonitorStatus::Down);
        $this->travel(2)->minutes();

        $lock = app(PageCache::class)->renderLock($this->page->id);
        $this->assertTrue($lock->get());
        try {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $this->get('/status/acme')->assertOk()->assertHeaderMissing('X-Status-Stale');
            $this->assertSame($warm->getContent(), $response->getContent());
            $this->assertSame([], DB::getQueryLog());
        } finally {
            $lock->release();
        }

        $this->get('/status/acme')->assertOk()->assertSee('Major outage');
    }

    public function test_public_routes_are_rate_limited_per_ip(): void
    {
        $this->get('/status/acme')->assertOk()->assertHeader('X-RateLimit-Limit', '300');
    }

    private function setStatus(MonitorStatus $status): void
    {
        DB::table('monitor_states')->where('monitor_id', $this->monitor->id)->update(['status' => $status->value]);
    }

    /**
     * Runs the callback with the default connection pointing at a server that refuses connections.
     */
    private function withDeadDatabase(callable $callback): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.dead' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'none',
            'username' => 'none',
            'password' => 'none',
        ]]);
        DB::setDefaultConnection('dead');
        try {
            $callback();
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('dead');
        }
    }
}
