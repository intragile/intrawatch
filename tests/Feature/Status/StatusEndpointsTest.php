<?php

namespace Tests\Feature\Status;

use App\Models\Maintenance;
use App\Models\StatusPageItem;
use App\Status\Pages\PageCache;
use App\Status\Pages\PageStamps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StatusEndpointsTest extends TestCase
{
    use PublicFeedFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFixture();
    }

    /**
     * @return array<string, array{string, string}> Path and cache variant of each machine-readable endpoint.
     */
    public static function endpoints(): array
    {
        return [
            'atom' => ['/status/acme/feed.atom', 'atom'],
            'rss' => ['/status/acme/feed.rss', 'rss'],
            'summary' => ['/status/acme/summary.json', 'summary'],
            'badge' => ['/status/acme/badge.svg', 'badges'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_unpublished_page_is_404_like_a_missing_one(string $path, string $variant): void
    {
        $missing = $this->get(str_replace('acme', 'no-such-page', $path))->assertNotFound();
        $this->get($path)->assertOk();

        $this->page->update(['published' => false]);
        app(PageStamps::class)->forgetPage($this->page);

        $response = $this->get($path)->assertNotFound();
        $this->assertSame($missing->getContent(), $response->getContent());
    }

    #[DataProvider('endpoints')]
    public function test_outage_serves_a_stale_copy_or_a_503(string $path, string $variant): void
    {
        $warm = $this->get($path)->assertOk();
        $this->travel(2)->minutes();

        $this->withDeadDatabase(function () use ($path, $warm): void {
            $stale = $this->get($path)->assertOk()->assertHeader('X-Status-Stale', '1');
            $this->assertSame($warm->getContent(), $stale->getContent());
        });

        Cache::store('status_pages')->forget(PageStamps::variantLastGoodKey($this->page->id, $variant));
        Cache::store('status_pages')->forget(PageStamps::variantKey($this->page->id, $variant));
        $this->withDeadDatabase(function () use ($path): void {
            $response = $this->get($path)->assertStatus(503)->assertHeader('Retry-After', '30');
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        });
    }

    public function test_editing_a_page_drops_every_rendering(): void
    {
        foreach (self::endpoints() as [$path]) {
            $this->get($path)->assertOk();
        }
        $store = Cache::store('status_pages');
        foreach (PageCache::VARIANTS as $variant) {
            $this->assertTrue($store->has(PageStamps::variantLastGoodKey($this->page->id, $variant)), $variant);
        }

        $this->page->update(['title' => 'Renamed']);
        app(PageStamps::class)->forgetPage($this->page);
        foreach (PageCache::VARIANTS as $variant) {
            $this->assertFalse($store->has(PageStamps::variantKey($this->page->id, $variant)), $variant);
            $this->assertFalse($store->has(PageStamps::variantLastGoodKey($this->page->id, $variant)), $variant);
        }

        // With the slug known again and the database gone, nothing from before the edit is served.
        $this->get('/status/acme')->assertOk();
        $this->withDeadDatabase(function (): void {
            $this->get('/status/acme/summary.json')->assertStatus(503);
            $this->get('/status/acme/badge.svg')->assertStatus(503);
        });
    }

    public function test_badge_is_n_a_for_hidden_uptime_and_latency(): void
    {
        $uptime = '/status/acme/badge/'.$this->monitor->id.'/uptime.svg';
        $latency = '/status/acme/badge/'.$this->monitor->id.'/latency.svg';
        $this->assertStringContainsString('99%', (string) $this->get($uptime.'?days=7')->getContent());

        StatusPageItem::query()->update(['show_uptime' => false]);
        $this->page->update(['show_latency' => false]);
        app(PageStamps::class)->forgetPage($this->page);

        foreach (['', '?days=90', '?days=365'] as $query) {
            $this->assertStringContainsString('n/a', (string) $this->get($uptime.$query)->getContent(), $query);
        }
        $this->assertStringContainsString('latency: n/a', (string) $this->get($latency)->getContent());
    }

    public function test_long_uptime_badges_use_the_shown_flag_not_the_page_window(): void
    {
        // Only the last three days have rollups; a page window of 1 day still leaves 90 and 365 days readable.
        $this->page->update(['uptime_days' => 1]);
        app(PageStamps::class)->forgetPage($this->page);
        $uptime = '/status/acme/badge/'.$this->monitor->id.'/uptime.svg';
        $this->assertStringContainsString('99%', (string) $this->get($uptime.'?days=90')->getContent());
        $this->assertStringContainsString('99%', (string) $this->get($uptime.'?days=365')->getContent());
    }

    public function test_feed_lists_maintenance_ended_within_30_days_only(): void
    {
        $recent = Maintenance::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Ended ten days ago',
            'starts_at' => $this->now->subDays(10)->subHour(),
            'ends_at' => $this->now->subDays(10),
        ]);
        $old = Maintenance::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Ended forty days ago',
            'starts_at' => $this->now->subDays(40)->subHour(),
            'ends_at' => $this->now->subDays(40),
        ]);
        $recent->monitors()->attach($this->monitor->id);
        $old->monitors()->attach($this->monitor->id);

        $body = (string) $this->get('/status/acme/feed.atom')->assertOk()->getContent();
        $this->assertStringContainsString('Ended ten days ago', $body);
        $this->assertStringNotContainsString('Ended forty days ago', $body);
        $this->assertStringContainsString('#maintenance-'.$recent->id, $body);

        $xml = simplexml_load_string($body);
        $this->assertNotFalse($xml);
        foreach ($xml->entry as $entry) {
            if (str_contains((string) $entry->id, '#maintenance-'.$recent->id)) {
                $this->assertSame('2026-10-28T12:30:00Z', (string) $entry->updated);
            }
        }
    }

    public function test_feed_never_dates_an_entry_in_the_future(): void
    {
        $body = (string) $this->get('/status/acme/feed.atom')->assertOk()->getContent();
        $xml = simplexml_load_string($body);
        $this->assertNotFalse($xml);
        foreach ($xml->entry as $entry) {
            $this->assertLessThanOrEqual($this->now->getTimestamp(), strtotime((string) $entry->updated));
            $this->assertLessThanOrEqual($this->now->getTimestamp(), strtotime((string) $entry->published));
        }
        $this->assertLessThanOrEqual($this->now->getTimestamp(), strtotime((string) $xml->updated));

        $rss = simplexml_load_string((string) $this->get('/status/acme/feed.rss')->getContent());
        $this->assertNotFalse($rss);
        $this->assertLessThanOrEqual($this->now->getTimestamp(), strtotime((string) $rss->channel->lastBuildDate));
    }

    public function test_badge_with_xml_invalid_characters_is_still_valid_svg(): void
    {
        $label = "a\x01b\u{FFFE}c\u{FFFF}d";
        $svg = (string) $this->get('/status/acme/badge/'.$this->monitor->id.'/status.svg?label='.urlencode($label))->assertOk()->getContent();
        $this->assertNotFalse(simplexml_load_string($svg));
        $this->assertStringContainsString('abcd: ', $svg);

        $this->page->update(['title' => "T\u{FFFE}itle\x0B"]);
        app(PageStamps::class)->forgetPage($this->page);
        $overall = (string) $this->get('/status/acme/badge.svg')->assertOk()->getContent();
        $this->assertNotFalse(simplexml_load_string($overall));
        $this->assertStringContainsString('Title: ', $overall);
    }
}
