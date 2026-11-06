<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_ping_reports_status_and_version(): void
    {
        config(['app.version' => '1.2.3']);

        $this->getJson('/api/v1/ping')
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'version' => '1.2.3']);
    }

    public function test_up_route_is_healthy(): void
    {
        $this->get('/up')->assertOk();
    }
}
