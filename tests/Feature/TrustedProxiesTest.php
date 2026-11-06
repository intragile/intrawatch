<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('TRUSTED_PROXIES');
        TrustProxies::flushState();

        parent::tearDown();
    }

    private function clientIp(?string $trusted): string
    {
        $trusted === null ? putenv('TRUSTED_PROXIES') : putenv("TRUSTED_PROXIES={$trusted}");
        $this->refreshApplication();

        Route::get('/_ip', fn (Request $request) => $request->ip());

        $content = $this->withHeaders(['X-Forwarded-For' => '203.0.113.9'])->get('/_ip')->getContent();
        $this->assertIsString($content);

        return $content;
    }

    public function test_forwarded_header_is_ignored_when_unset(): void
    {
        $this->assertSame('127.0.0.1', $this->clientIp(null));
    }

    public function test_forwarded_header_is_honoured_for_a_trusted_proxy(): void
    {
        $this->assertSame('203.0.113.9', $this->clientIp('127.0.0.1'));
    }

    public function test_wildcard_and_lists_are_accepted(): void
    {
        $this->assertSame('203.0.113.9', $this->clientIp('*'));
        TrustProxies::flushState();
        $this->assertSame('203.0.113.9', $this->clientIp('10.0.0.1, 127.0.0.1'));
    }

    public function test_untrusted_proxy_list_ignores_the_header(): void
    {
        $this->assertSame('127.0.0.1', $this->clientIp('10.0.0.1'));
    }
}
