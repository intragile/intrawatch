<?php

namespace Tests\Feature\Status;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatusSummaryTest extends TestCase
{
    use PublicFeedFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFixture();
    }

    public function test_summary_shape_and_cors(): void
    {
        $response = $this->get('/status/acme/summary.json')->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeaderMissing('Set-Cookie');

        $this->assertSame([
            'page' => [
                'title' => 'Acme <status> & co',
                'url' => 'https://watch.example.com/status/acme',
                'status' => 'degraded',
                'updated_at' => '2026-11-07T12:30:00Z',
            ],
            'components' => [['id' => $this->monitor->id, 'name' => 'Website', 'status' => 'degraded']],
            'incidents' => [[
                'id' => $this->incident->id,
                'title' => 'Slow <b>site</b>',
                'impact' => 'minor',
                'status' => 'monitoring',
                'url' => 'https://watch.example.com/status/acme#incident-'.$this->incident->id,
            ]],
            'maintenances' => [[
                'id' => $this->maintenance->id,
                'title' => 'Database upgrade',
                'starts_at' => '2026-11-08T12:30:00Z',
                'ends_at' => '2026-11-08T13:30:00Z',
            ]],
        ], $response->json());
    }

    public function test_summary_cache_hit_makes_no_queries(): void
    {
        $this->get('/status/acme/summary.json')->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/status/acme/summary.json')->assertOk();
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_unknown_page_is_404(): void
    {
        $this->get('/status/nope/summary.json')->assertNotFound();
    }
}
