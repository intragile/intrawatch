<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class NoSniffTest extends TestCase
{
    public function test_api_json_answers_forbid_content_sniffing(): void
    {
        $this->getJson('/api/v1/ping')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson('/api/v1/no-such-endpoint')->assertNotFound()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
