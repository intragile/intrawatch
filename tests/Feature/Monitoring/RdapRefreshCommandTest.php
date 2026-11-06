<?php

namespace Tests\Feature\Monitoring;

use App\Monitoring\Domain\RdapBootstrap;
use App\Monitoring\Domain\RegistrableDomains;
use Closure;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private static function snapshot(): string
    {
        return (string) file_get_contents(base_path(RegistrableDomains::SNAPSHOT_PATH));
    }

    public function test_refresh_writes_both_lists_to_storage_and_they_take_precedence(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::BOOTSTRAP => Http::response(json_encode(['version' => '1.0', 'services' => [[['example'], ['https://rdap.example/']], [['com'], ['https://rdap.example.com/']]]], JSON_THROW_ON_ERROR)),
            self::PSL => Http::response(str_replace("// ===BEGIN ICANN DOMAINS===\n", "// ===BEGIN ICANN DOMAINS===\nexample\nco.example\n", self::snapshot())),
        ]);

        $this->assertSame(0, Artisan::call('intrawatch:rdap:refresh'));

        $this->assertFileExists(storage_path(RdapBootstrap::STORAGE_PATH));
        $this->assertSame('https://rdap.example/', RdapBootstrap::load()->baseFor('example'));
        $this->assertNull(RdapBootstrap::load()->baseFor('uk'));
        $this->assertSame('shop.co.example', RegistrableDomains::load()->registrable('www.shop.co.example'));
    }

    /**
     * @return array<string, array{0: Closure(string): string}>
     */
    public static function brokenLists(): array
    {
        return [
            'not a list' => [fn (string $list): string => '<html>maintenance</html>'],
            'truncated' => [fn (string $list): string => substr($list, 0, intdiv(strlen($list), 2))],
            'missing end marker' => [fn (string $list): string => str_replace('===END ICANN DOMAINS===', '', $list)],
            'missing co.uk' => [fn (string $list): string => (string) preg_replace('/^co\.uk$/m', '', $list)],
            'missing com' => [fn (string $list): string => (string) preg_replace('/^com$/m', '', $list)],
        ];
    }

    /**
     * @param  Closure(string): string  $break
     */
    #[DataProvider('brokenLists')]
    public function test_an_incomplete_suffix_list_is_rejected(Closure $break): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::BOOTSTRAP => Http::response('{"services": []}'),
            self::PSL => Http::response($break(self::snapshot())),
        ]);

        $this->assertSame(1, Artisan::call('intrawatch:rdap:refresh'));
        $this->assertFileDoesNotExist(storage_path(RegistrableDomains::STORAGE_PATH));
    }

    public function test_a_bootstrap_without_com_is_rejected(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::BOOTSTRAP => Http::response(json_encode(['services' => [[['example'], ['https://rdap.example/']]]], JSON_THROW_ON_ERROR)),
            self::PSL => Http::response(self::snapshot()),
        ]);

        $this->assertSame(1, Artisan::call('intrawatch:rdap:refresh'));
        $this->assertFileDoesNotExist(storage_path(RdapBootstrap::STORAGE_PATH));
        $this->assertFileExists(storage_path(RegistrableDomains::STORAGE_PATH));
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
