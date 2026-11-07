<?php

namespace Tests\Feature\Status;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatusFeedTest extends TestCase
{
    use PublicFeedFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFixture();
    }

    public function test_atom_is_valid_and_uses_the_page_url(): void
    {
        $response = $this->get('/status/acme/feed.atom', ['Host' => 'evil.example.org'])->assertOk();
        $response->assertHeader('Content-Type', 'application/atom+xml; charset=utf-8');
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('evil.example.org', $body);

        $xml = simplexml_load_string($body);
        $this->assertNotFalse($xml);
        $xml->registerXPathNamespace('a', 'http://www.w3.org/2005/Atom');
        $this->assertSame('Acme <status> & co', (string) $xml->title);
        $this->assertSame('https://watch.example.com/status/acme', (string) $xml->id);

        $entries = $xml->xpath('//a:entry');
        $this->assertIsArray($entries);
        $this->assertCount(2, $entries);
        $ids = array_map(static fn (\SimpleXMLElement $e): string => (string) $e->id, $entries);
        $this->assertContains('https://watch.example.com/status/acme#incident-'.$this->incident->id, $ids);
        $this->assertContains('https://watch.example.com/status/acme#maintenance-'.$this->maintenance->id, $ids);

        $incident = $entries[array_search('https://watch.example.com/status/acme#incident-'.$this->incident->id, $ids, true)];
        $html = (string) $incident->content;
        $this->assertSame('html', (string) $incident->content['type']);
        $this->assertLessThan(strpos($html, 'Fix deployed'), strpos($html, 'Looking into it'));
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertSame('Slow <b>site</b>', (string) $incident->title);
    }

    public function test_rss_is_valid_and_uses_the_page_url(): void
    {
        $response = $this->get('/status/acme/feed.rss')->assertOk();
        $response->assertHeader('Content-Type', 'application/rss+xml; charset=utf-8');

        $xml = simplexml_load_string((string) $response->getContent());
        $this->assertNotFalse($xml);
        $this->assertSame('2.0', (string) $xml['version']);
        $this->assertSame('https://watch.example.com/status/acme', (string) $xml->channel->link);
        $this->assertCount(2, $xml->channel->item);
        foreach ($xml->channel->item as $item) {
            $this->assertStringStartsWith('https://watch.example.com/status/acme#', (string) $item->link);
        }
    }

    public function test_feed_is_cached_and_answers_304_without_queries(): void
    {
        $etag = (string) $this->get('/status/acme/feed.atom')->assertOk()->headers->get('ETag');

        $this->get('/status/acme/feed.rss')->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/status/acme/feed.rss')->assertOk();
        $this->get('/status/acme/feed.atom')->assertOk();
        $this->get('/status/acme/feed.atom', ['If-None-Match' => $etag])->assertStatus(304);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_unknown_and_unpublished_pages_have_no_feed(): void
    {
        $this->get('/status/nope/feed.atom')->assertNotFound();
        $this->get('/feed.atom')->assertNotFound();
    }

    public function test_custom_domain_serves_feed_with_its_own_url(): void
    {
        $this->page->update(['custom_domain' => 'status.acme.test']);
        $response = $this->get('http://status.acme.test/feed.atom')->assertOk();
        $this->assertStringContainsString('<id>https://status.acme.test</id>', (string) $response->getContent());
    }
}
