<?php

namespace Tests\Feature\Status;

use App\Enums\DisplayStatus;
use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\WorkspaceRole;
use App\Events\MonitorStatusChanged;
use App\Models\CheckRollup;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\StatusPageItem;
use App\Models\StatusPageSection;
use App\Models\User;
use App\Models\Workspace;
use App\Status\Pages\DayBar;
use App\Status\Pages\ItemView;
use App\Status\Pages\PageCache;
use App\Status\Pages\PageRenderer;
use App\Status\Pages\PageStamps;
use App\Status\Pages\PageView;
use App\Status\Pages\SectionView;
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
            ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' https: data:; font-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors *")
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
        $this->get('http://other.test/')->assertRedirect('/admin/');
        $this->get('http://192.168.1.10/status/acme')->assertOk();
    }

    public function test_unpublished_custom_domain_is_reserved(): void
    {
        $this->page->update(['custom_domain' => 'status.example.test', 'published' => false]);
        app(PageStamps::class)->forgetPage($this->page);

        // The host belongs to the page even while it is unpublished: neither the admin, the API nor the
        // session endpoints are reachable through it, and the page itself is not served.
        $this->get('http://status.example.test/admin/')->assertNotFound();
        $this->getJson('http://status.example.test/api/v1/ping')->assertNotFound();
        $this->getJson('http://status.example.test/api/v1/setup')->assertNotFound();
        $this->get('http://status.example.test/sanctum/csrf-cookie')->assertNotFound();
        $this->postJson('http://status.example.test/broadcasting/auth')->assertNotFound();
        $this->get('http://status.example.test/')->assertNotFound();
        $this->get('http://status.example.test/up')->assertOk();

        // Any other host is unaffected.
        $this->getJson('http://other.test/api/v1/ping')->assertOk();
    }

    public function test_api_and_admin_are_closed_on_foreign_hosts_while_nothing_is_known_and_the_database_is_down(): void
    {
        $this->withDeadDatabase(function (): void {
            $this->getJson('http://maybe.example.org/api/v1/ping')->assertNotFound();
            $this->get('http://maybe.example.org/admin/')->assertNotFound();
            $this->get('http://maybe.example.org/sanctum/csrf-cookie')->assertNotFound();
            $this->postJson('http://maybe.example.org/broadcasting/auth')->assertNotFound();
            $this->get('http://maybe.example.org/up')->assertOk();
            // The installation's own host and IP addresses never depend on the domain map.
            $this->getJson('https://watch.example.com/api/v1/ping')->assertOk();
            $this->getJson('http://192.168.1.10/api/v1/ping')->assertOk();
        });
    }

    public function test_domain_map_falls_back_to_the_last_good_copy_during_an_outage(): void
    {
        $unpublished = StatusPage::factory()->create([
            'workspace_id' => $this->workspace->id,
            'slug' => 'hidden',
            'custom_domain' => 'hidden.example.test',
            'published' => false,
        ]);
        $this->page->update(['custom_domain' => 'status.example.test']);
        app(PageStamps::class)->forgetPage($this->page);
        $this->get('http://status.example.test/')->assertOk();

        // A page write drops the fresh map right before the database goes away.
        app(PageStamps::class)->forgetPage($unpublished);

        $this->withDeadDatabase(function (): void {
            $this->getJson('http://hidden.example.test/api/v1/ping')->assertNotFound();
            $this->get('http://hidden.example.test/admin/')->assertNotFound();
            $this->getJson('http://status.example.test/api/v1/ping')->assertNotFound();
            $this->get('http://status.example.test/')->assertOk()->assertSee('Acme status');
        });
    }

    public function test_status_rate_limit_comes_from_configuration(): void
    {
        config(['status.rate_limit.per_minute' => 2]);

        $this->get('/status/acme')->assertOk()->assertHeader('X-RateLimit-Limit', '2');
        $this->get('/status/acme')->assertOk();
        $this->get('/status/acme')->assertStatus(429);
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
        $this->travel(90)->seconds();

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

    public function test_database_outage_backs_off_after_a_failed_attempt(): void
    {
        $this->get('/status/acme')->assertOk();
        // Past the slug's 300 s freshness and the page's TTL.
        $this->travel(6)->minutes();

        $this->withDeadDatabase(function (\Closure $attempts): void {
            $this->get('/status/acme')->assertOk()->assertHeader('X-Status-Stale', '1');
            $this->assertGreaterThan(0, $attempts());
            $tried = $attempts();

            $this->travel(20)->seconds();
            $this->get('/status/acme')->assertOk()->assertHeader('X-Status-Stale', '1');
            $this->assertSame($tried, $attempts(), 'the database was tried again within 30 s');

            $this->travel(11)->seconds();
            $this->get('/status/acme')->assertOk()->assertHeader('X-Status-Stale', '1');
            $this->assertGreaterThan($tried, $attempts());
        });
    }

    public function test_a_failed_connection_stops_every_uncached_request_from_reconnecting_for_30_seconds(): void
    {
        StatusPage::factory()->create(['workspace_id' => $this->workspace->id, 'slug' => 'other', 'published' => true]);

        $this->withDeadDatabase(function (\Closure $attempts): void {
            $this->get('/status/acme')->assertStatus(503)->assertHeader('Retry-After', '30');
            $tried = $attempts();
            $this->assertGreaterThan(0, $tried);

            // Other pages and endpoints know nothing either, yet none of them tries the database again.
            $this->get('/status/other')->assertStatus(503);
            $this->get('/status/acme/summary.json')->assertStatus(503);
            $this->get('/status/acme/feed.rss')->assertStatus(503);
            $this->travel(29)->seconds();
            $this->get('/status/other')->assertStatus(503);
            $this->assertSame($tried, $attempts(), 'no request reconnected while the marker was set');

            $this->travel(2)->seconds();
            $this->get('/status/other')->assertStatus(503);
            $this->assertGreaterThan($tried, $attempts(), 'the database is tried again once the marker expired');
        });

        // Back up: the next request after the marker renders normally.
        $this->travel(31)->seconds();
        $this->get('/status/acme')->assertOk()->assertSee('Acme status');
    }

    public function test_a_failed_connection_keeps_serving_cached_copies(): void
    {
        $this->get('/status/acme')->assertOk();
        $this->travel(2)->minutes();

        $this->withDeadDatabase(function (\Closure $attempts): void {
            $this->get('/status/no-such-page')->assertStatus(503);
            $tried = $attempts();
            $this->get('/status/acme')->assertOk()->assertHeader('X-Status-Stale', '1')->assertSee('Acme status');
            $this->assertSame($tried, $attempts());
        });
    }

    public function test_unknown_hosts_cost_no_query_and_no_cache_entry(): void
    {
        $this->page->update(['custom_domain' => 'status.example.test']);
        app(PageStamps::class)->forgetPage($this->page);
        $this->get('http://warm.example.org/up')->assertOk();
        $files = $this->cacheFiles();

        DB::enableQueryLog();
        DB::flushQueryLog();
        for ($i = 0; $i < 20; $i++) {
            $this->get('http://h'.bin2hex(random_bytes(6)).'.example.org/up')->assertOk();
        }
        $this->assertSame([], DB::getQueryLog());
        $this->assertSame($files, $this->cacheFiles());

        // When the map is due, all hosts share one refresh query.
        $this->travel(61)->seconds();
        DB::flushQueryLog();
        for ($i = 0; $i < 5; $i++) {
            $this->get('http://h'.bin2hex(random_bytes(6)).'.example.org/up')->assertOk();
        }
        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_custom_domain_change_takes_effect_at_once(): void
    {
        $this->get('http://status.example.test/')->assertRedirect('/admin/');

        $this->page->update(['custom_domain' => 'status.example.test']);
        app(PageStamps::class)->forgetPage($this->page);
        $this->get('http://status.example.test/')->assertOk()->assertSee('Acme status');

        $oldDomain = $this->page->custom_domain;
        $this->page->update(['custom_domain' => 'new.example.test']);
        app(PageStamps::class)->forgetKeys($this->page->id, $this->page->slug, $oldDomain);
        app(PageStamps::class)->forgetPage($this->page);
        $this->get('http://status.example.test/')->assertRedirect('/admin/');
        $this->get('http://new.example.test/')->assertOk();
    }

    public function test_custom_domain_resolves_during_a_database_outage(): void
    {
        $this->page->update(['custom_domain' => 'status.example.test']);
        app(PageStamps::class)->forgetPage($this->page);
        $this->get('http://status.example.test/')->assertOk();
        $this->travel(2)->minutes();

        $this->withDeadDatabase(function (\Closure $attempts): void {
            $this->get('http://status.example.test/')->assertOk()->assertHeader('X-Status-Stale', '1')->assertSee('Acme status');
            $tried = $attempts();
            $this->get('http://status.example.test/api/v1/me')->assertNotFound();
            $this->get('http://other.example.org/up')->assertOk();
            $this->assertSame($tried, $attempts());
        });
    }

    public function test_worst_case_page_stays_small(): void
    {
        $bars = [];
        for ($i = 0; $i < 365; $i++) {
            $bars[] = new DayBar(CarbonImmutable::parse('2025-11-08')->addDays($i)->format('Y-m-d'), DisplayStatus::Operational, 100.0, 0);
        }
        $items = [];
        for ($i = 0; $i < 250; $i++) {
            $itemBars = $i === 0
                ? [...array_slice($bars, 0, 100), new DayBar($bars[100]->date, DisplayStatus::MajorOutage, 80.0, 288), ...array_slice($bars, 101)]
                : $bars;
            $items[] = new ItemView($i + 1, 'Component '.$i, null, DisplayStatus::Operational, 99.95, $itemBars, array_fill(0, 24, 120), []);
        }
        $view = new PageView('Big', null, DisplayStatus::Operational, 'all_operational', [new SectionView(1, 'All', null, $items)],
            [], [], [], [], null, $this->now, 'UTC', 'en');

        $html = app(PageRenderer::class)->render($this->page, $view);

        $this->assertLessThan(2_500_000, strlen($html));
        $this->assertStringContainsString('<title>'.CarbonImmutable::parse($bars[100]->date)->isoFormat('MMM D, YYYY').' · Major outage · 80.00%</title>', $html);
        $this->assertStringContainsString('aria-label="Component 0, last 365 days · Uptime: 99.95%"', $html);
    }

    public function test_etag_survives_a_rerender_of_unchanged_content(): void
    {
        $first = $this->get('/status/acme')->assertOk();
        $this->travel(61)->seconds();

        $second = $this->get('/status/acme', ['If-None-Match' => (string) $first->headers->get('ETag')]);
        $second->assertStatus(304);
    }

    public function test_error_answers_are_not_cached(): void
    {
        $this->get('/status/no-such-page')->assertNotFound()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->withDeadDatabase(function (): void {
            $this->get('/status/acme')->assertStatus(503)
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        });
    }

    private function editor(): User
    {
        return User::factory()->memberOf($this->workspace, WorkspaceRole::Editor)->create();
    }

    private function api(string $path): string
    {
        return "/api/v1/workspaces/{$this->workspace->slug}{$path}";
    }

    /**
     * The page as a visitor gets it while the database is down: never with content that was removed before.
     */
    private function assertOutageHides(string $removed): void
    {
        $this->withDeadDatabase(function () use ($removed): void {
            $response = $this->get('/status/acme');
            $this->assertContains($response->getStatusCode(), [200, 503]);
            $this->assertStringNotContainsString($removed, (string) $response->getContent());
            foreach (['/status/acme/feed.atom', '/status/acme/summary.json'] as $path) {
                $this->assertStringNotContainsString($removed, (string) $this->get($path)->getContent(), $path);
            }
        });
    }

    private function warmEverything(string $expected): void
    {
        $this->get('/status/acme')->assertOk()->assertSee($expected);
        $this->get('/status/acme/feed.atom')->assertOk();
        $this->get('/status/acme/summary.json')->assertOk();
    }

    public function test_a_replaced_layout_is_never_served_from_the_last_good_copy(): void
    {
        $this->warmEverything('Website');

        $this->actingAs($this->editor())->putJson($this->api("/status-pages/{$this->page->id}/layout"), ['sections' => [['name' => 'Public', 'items' => []]]])->assertOk();

        $this->assertOutageHides('Website');
    }

    public function test_a_deleted_incident_is_never_served_from_the_last_good_copy(): void
    {
        $id = $this->actingAs($this->editor())->postJson($this->api('/incidents'), [
            'title' => 'Leaked internal hostname', 'impact' => 'minor', 'status' => 'investigating', 'message' => 'db-7.internal is down', 'status_page_ids' => [$this->page->id],
        ])->assertCreated()->json('data.id');
        $this->assertIsInt($id);
        $this->warmEverything('Leaked internal hostname');

        $this->actingAs($this->editor())->deleteJson($this->api("/incidents/{$id}"))->assertNoContent();

        $this->assertOutageHides('Leaked internal hostname');
    }

    public function test_an_unlinked_incident_is_never_served_from_the_last_good_copy(): void
    {
        $id = $this->actingAs($this->editor())->postJson($this->api('/incidents'), [
            'title' => 'Posted on the wrong page', 'impact' => 'minor', 'status' => 'investigating', 'message' => 'oops', 'status_page_ids' => [$this->page->id],
        ])->assertCreated()->json('data.id');
        $this->assertIsInt($id);
        $this->warmEverything('Posted on the wrong page');

        $this->actingAs($this->editor())->patchJson($this->api("/incidents/{$id}"), ['status_page_ids' => []])->assertOk();

        $this->assertOutageHides('Posted on the wrong page');
    }

    public function test_a_deleted_maintenance_is_never_served_from_the_last_good_copy(): void
    {
        $id = $this->actingAs($this->editor())->postJson($this->api('/maintenances'), [
            'title' => 'Secret migration', 'strategy' => 'once', 'starts_at' => '2026-11-08T10:00:00Z', 'ends_at' => '2026-11-08T11:00:00Z',
            'monitor_ids' => [$this->monitor->id], 'status_page_ids' => [$this->page->id],
        ])->assertCreated()->json('data.id');
        $this->assertIsInt($id);
        $this->warmEverything('Secret migration');

        $this->actingAs($this->editor())->deleteJson($this->api("/maintenances/{$id}"))->assertNoContent();

        $this->assertOutageHides('Secret migration');
    }

    public function test_a_deleted_monitor_is_never_served_from_the_last_good_copy(): void
    {
        $this->monitor->update(['name' => 'Internal billing host']);
        $this->warmEverything('Internal billing host');

        $this->actingAs($this->editor())->deleteJson($this->api("/monitors/{$this->monitor->id}"))->assertNoContent();

        $this->assertOutageHides('Internal billing host');
    }

    public function test_a_bulk_deleted_monitor_is_never_served_from_the_last_good_copy(): void
    {
        $this->monitor->update(['name' => 'Internal billing host']);
        $this->warmEverything('Internal billing host');

        $this->actingAs($this->editor())->postJson($this->api('/monitors/bulk'), ['action' => 'delete', 'ids' => [$this->monitor->id]])->assertOk();

        $this->assertOutageHides('Internal billing host');
    }

    public function test_maintenance_writes_reach_the_page_at_once(): void
    {
        $this->get('/status/acme')->assertOk()->assertDontSee('Router firmware');

        $id = $this->actingAs($this->editor())->postJson($this->api('/maintenances'), [
            'title' => 'Router firmware', 'strategy' => 'once', 'starts_at' => '2026-11-08T10:00:00Z', 'ends_at' => '2026-11-08T11:00:00Z',
            'monitor_ids' => [$this->monitor->id],
        ])->assertCreated()->json('data.id');
        $this->assertIsInt($id);
        $this->get('/status/acme')->assertOk()->assertSee('Router firmware');

        $this->actingAs($this->editor())->patchJson($this->api("/maintenances/{$id}"), ['title' => 'Switch firmware'])->assertOk();
        $this->get('/status/acme')->assertOk()->assertSee('Switch firmware')->assertDontSee('Router firmware');

        $this->actingAs($this->editor())->deleteJson($this->api("/maintenances/{$id}"))->assertNoContent();
        $this->get('/status/acme')->assertOk()->assertDontSee('Switch firmware');
    }

    public function test_the_publish_toggle_takes_effect_at_once(): void
    {
        $this->get('/status/acme')->assertOk();
        $this->get('/status/acme/summary.json')->assertOk();

        $this->actingAs($this->editor())->patchJson($this->api("/status-pages/{$this->page->id}"), ['published' => false])->assertOk();
        $this->get('/status/acme')->assertNotFound();
        $this->get('/status/acme/summary.json')->assertNotFound();

        $this->actingAs($this->editor())->patchJson($this->api("/status-pages/{$this->page->id}"), ['published' => true])->assertOk();
        $this->get('/status/acme')->assertOk()->assertSee('Acme status');
    }

    /**
     * @return list<string>
     */
    private function cacheFiles(): array
    {
        $path = config()->string('cache.stores.status_pages.path');
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function setStatus(MonitorStatus $status): void
    {
        DB::table('monitor_states')->where('monitor_id', $this->monitor->id)->update(['status' => $status->value]);
    }

    /**
     * Runs the callback with the default connection pointing at a server that refuses connections.
     *
     * @param  callable(\Closure(): int): void  $callback  Receives a counter of the queries tried on the dead connection.
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
        $attempts = 0;
        DB::connection('dead')->beforeExecuting(function () use (&$attempts): void {
            $attempts++;
        });
        try {
            $callback(function () use (&$attempts): int {
                return $attempts;
            });
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('dead');
        }
    }
}
