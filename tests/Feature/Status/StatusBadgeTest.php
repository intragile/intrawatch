<?php

namespace Tests\Feature\Status;

use App\Status\Pages\Badge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatusBadgeTest extends TestCase
{
    use PublicFeedFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFixture();
    }

    public function test_overall_badge_uses_the_page_title_and_status(): void
    {
        $response = $this->get('/status/acme/badge.svg')->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')
            ->assertHeader('Cache-Control', 'max-age=60, public');
        $svg = (string) $response->getContent();
        $this->assertNotFalse(simplexml_load_string($svg));
        $this->assertStringContainsString('Acme &lt;status&gt; &amp; co: degraded', $svg);
        $this->assertStringContainsString('#dfb317', $svg);
    }

    public function test_badge_only_for_monitors_on_the_page(): void
    {
        $this->get('/status/acme/badge/'.$this->monitor->id.'/status.svg')->assertOk();
        $this->get('/status/acme/badge/'.$this->other->id.'/status.svg')->assertNotFound();
        $this->get('/status/acme/badge/999999/uptime.svg')->assertNotFound();
        $this->get('/status/acme/badge/'.$this->monitor->id.'/bogus.svg')->assertNotFound();
        $this->get('/status/nope/badge/'.$this->monitor->id.'/status.svg')->assertNotFound();
    }

    public function test_uptime_and_latency_badges(): void
    {
        $uptime = (string) $this->get('/status/acme/badge/'.$this->monitor->id.'/uptime.svg?days=7')->assertOk()->getContent();
        $this->assertStringContainsString('uptime 7d: 99%', $uptime);
        $this->assertStringContainsString('#a4a61d', $uptime);

        $fallback = (string) $this->get('/status/acme/badge/'.$this->monitor->id.'/uptime.svg?days=abc&style=round')->getContent();
        $this->assertStringContainsString('uptime 30d', $fallback);
        $this->assertStringContainsString('rx="3"', $fallback);

        $square = (string) $this->get('/status/acme/badge/'.$this->monitor->id.'/uptime.svg?style=flat-square')->getContent();
        $this->assertStringContainsString('rx="0"', $square);

        $latency = (string) $this->get('/status/acme/badge/'.$this->monitor->id.'/latency.svg')->assertOk()->getContent();
        $this->assertStringContainsString('latency: 150 ms', $latency);
    }

    public function test_badge_escapes_label(): void
    {
        $svg = (string) $this->get('/status/acme/badge/'.$this->monitor->id.'/status.svg?label='.urlencode('<x>"&'))->assertOk()->getContent();
        $this->assertStringNotContainsString('<x>', $svg);
        $this->assertStringContainsString('&lt;x&gt;&quot;&amp;', $svg);
        $this->assertNotFalse(simplexml_load_string($svg));

        $long = (string) $this->get('/status/acme/badge/'.$this->monitor->id.'/status.svg?label='.str_repeat('a', 41))->getContent();
        $this->assertStringContainsString('status: degraded', $long);
    }

    public function test_badge_cache_hit_makes_no_queries(): void
    {
        $path = '/status/acme/badge/'.$this->monitor->id.'/uptime.svg';
        $etag = (string) $this->get($path)->assertOk()->headers->get('ETag');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get($path.'?days=90&label=x')->assertOk();
        $this->get('/status/acme/badge.svg')->assertOk();
        $this->get($path, ['If-None-Match' => $etag])->assertStatus(304);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_custom_domain_badges(): void
    {
        $this->page->update(['custom_domain' => 'status.acme.test']);
        $this->get('http://status.acme.test/badge.svg')->assertOk();
        $this->get('http://status.acme.test/badge/'.$this->monitor->id.'/status.svg')->assertOk();
        $this->get('http://status.acme.test/badge/'.$this->other->id.'/status.svg')->assertNotFound();
    }

    public function test_render_widths_and_colours(): void
    {
        $short = Badge::render('a', 'b', '#4c1', 'flat');
        $long = Badge::render('a long label', 'b', '#4c1', 'flat');
        $this->assertGreaterThan($this->width($short), $this->width($long));
        $this->assertSame(Badge::render('x', 'y', '#4c1', 'flat'), Badge::render('x', 'y', '#4c1', 'flat'));
        $this->assertStringNotContainsString('linearGradient', Badge::render('x', 'y', '#4c1', 'flat-square'));
        $this->assertSame('#4c1', Badge::uptimeColor(99.9));
        $this->assertSame('#a4a61d', Badge::uptimeColor(99.89));
        $this->assertSame('#dfb317', Badge::uptimeColor(95));
        $this->assertSame('#e05d44', Badge::uptimeColor(94.99));
        $this->assertSame('100%', Badge::uptimeMessage(100.0));
        $this->assertSame('99.95%', Badge::uptimeMessage(99.95));
    }

    private function width(string $svg): float
    {
        $this->assertSame(1, preg_match('/<svg[^>]* width="([0-9.]+)"/', $svg, $m));

        return (float) ($m[1] ?? 0);
    }
}
