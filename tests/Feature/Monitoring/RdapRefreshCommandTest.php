<?php

namespace Tests\Feature\Monitoring;

use App\Monitoring\Domain\RdapBootstrap;
use App\Monitoring\Domain\RegistrableDomains;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RdapRefreshCommandTest extends TestCase
{
    private const BOOTSTRAP = 'https://data.iana.org/rdap/dns.json';

    private const PSL = 'https://publicsuffix.org/list/public_suffix_list.dat';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    private function clean(): void
    {
        foreach ([RdapBootstrap::STORAGE_PATH, RegistrableDomains::STORAGE_PATH] as $path) {
            @unlink(storage_path($path));
        }
    }

    public function test_refresh_writes_both_lists_to_storage_and_they_take_precedence(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::BOOTSTRAP => Http::response(json_encode(['version' => '1.0', 'services' => [[['example'], ['https://rdap.example/']]]], JSON_THROW_ON_ERROR)),
            self::PSL => Http::response("// ===BEGIN ICANN DOMAINS===\nexample\nco.example\n// ===END ICANN DOMAINS===\n"),
        ]);

        $this->assertSame(0, Artisan::call('intrawatch:rdap:refresh'));

        $this->assertFileExists(storage_path(RdapBootstrap::STORAGE_PATH));
        $this->assertSame('https://rdap.example/', RdapBootstrap::load()->baseFor('example'));
        $this->assertNull(RdapBootstrap::load()->baseFor('com'));
        $this->assertSame('shop.co.example', RegistrableDomains::load()->registrable('www.shop.co.example'));
        $this->assertNull(RegistrableDomains::load()->registrable('example.com'));
    }

    public function test_invalid_downloads_keep_the_current_lists(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::BOOTSTRAP => Http::response('{"services": "nope"}'),
            self::PSL => Http::response('<html>maintenance</html>'),
        ]);

        $this->assertSame(1, Artisan::call('intrawatch:rdap:refresh'));

        $this->assertFileDoesNotExist(storage_path(RdapBootstrap::STORAGE_PATH));
        $this->assertFileDoesNotExist(storage_path(RegistrableDomains::STORAGE_PATH));
        // The committed snapshots stay in use.
        $this->assertSame('https://rdap.verisign.com/com/v1/', RdapBootstrap::load()->baseFor('com'));
        $this->assertSame('example.co.uk', RegistrableDomains::load()->registrable('www.example.co.uk'));
    }

    public function test_refresh_is_scheduled_weekly(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'intrawatch:rdap:refresh'));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * 0', $events->firstOrFail()->expression);
    }
}
