<?php

namespace Tests\Feature\Monitoring\Checks;

use Amp\NullCancellation;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\DomainLookup;
use App\Models\Monitor;
use App\Models\MonitorEvent;
use App\Monitoring\Checks\CheckerRegistry;
use App\Monitoring\Checks\DomainChecker;
use App\Monitoring\Checks\Http\ClientFactory;
use App\Monitoring\Checks\Http\DomainFacts;
use App\Monitoring\Checks\HttpChecker;
use App\Monitoring\Domain\DomainExpiry;
use App\Monitoring\Domain\DomainExpiryService;
use App\Monitoring\Domain\NotRegistrableDomain;
use App\Monitoring\Domain\RdapBootstrap;
use App\Monitoring\Domain\RdapClient;
use App\Monitoring\Domain\RegistrableDomains;
use App\Monitoring\Domain\WhoisClient;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Engine\ResultRecorder;
use App\Monitoring\Net\GuardedConnector;
use App\Monitoring\Net\NetworkPolicy;
use App\Monitoring\Worker\Clock;
use App\Monitoring\Worker\SystemClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Revolt\EventLoop;
use Tests\Support\FakeClock;
use Tests\Support\HttpFixtureServer;
use Tests\Support\MonitorSpecFactory;
use Tests\Support\RdapFixtureServer;
use Tests\Support\StubDnsResolver;
use Tests\Support\WhoisFixtureServer;
use Tests\TestCase;
use Throwable;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

class DomainCheckerTest extends TestCase
{
    use RefreshDatabase;

    private const HOSTS = [
        'rdap.test' => ['127.0.0.1'],
        'other-rdap.test' => ['127.0.0.1'],
        'whois.test' => ['127.0.0.1'],
        'whois.iana.test' => ['127.0.0.1'],
        'whois.nic.de' => ['127.0.0.1'],
        'www.example.com' => ['127.0.0.1'],
    ];

    private ?RdapFixtureServer $rdap = null;

    private ?RdapFixtureServer $otherRdap = null;

    private ?WhoisFixtureServer $whois = null;

    private ?HttpFixtureServer $http = null;

    /** @var list<Throwable> */
    private array $loopErrors = [];

    protected function setUp(): void
    {
        parent::setUp();

        EventLoop::setErrorHandler(function (Throwable $e): void {
            $this->loopErrors[] = $e;
        });
    }

    protected function tearDown(): void
    {
        $this->rdap?->stop();
        $this->otherRdap?->stop();
        $this->whois?->stop();
        $this->http?->stop();
        EventLoop::setErrorHandler(null);

        $this->assertSame([], array_map(fn (Throwable $e): string => $e::class.': '.$e->getMessage(), $this->loopErrors), 'Unhandled errors in the event loop');

        parent::tearDown();
    }

    private function rdap(): RdapFixtureServer
    {
        return $this->rdap ??= new RdapFixtureServer;
    }

    private function whois(): WhoisFixtureServer
    {
        return $this->whois ??= new WhoisFixtureServer;
    }

    private function connector(): GuardedConnector
    {
        return new GuardedConnector(new StubDnsResolver(self::HOSTS), new NetworkPolicy([]));
    }

    /**
     * @param  array<string, string>|null  $bootstrap  TLD to RDAP base URL; com and uk point at the fixture by default.
     * @param  array<string, string>  $whoisServers
     */
    private function service(?Clock $clock = null, ?array $bootstrap = null, array $whoisServers = [], float $lookupTimeout = 10.0): DomainExpiryService
    {
        $base = 'http://rdap.test:'.$this->rdap()->port().'/';
        $whoisPort = (int) substr($this->whois()->server(), strrpos($this->whois()->server(), ':') + 1);

        return new DomainExpiryService(
            $this->app->make(RegistrableDomains::class),
            RdapBootstrap::fromArray($bootstrap ?? ['com' => $base, 'uk' => $base]),
            new RdapClient($this->connector()),
            new WhoisClient($this->connector(), $whoisPort),
            $clock ?? new SystemClock,
            $whoisServers,
            'whois.iana.test',
            $lookupTimeout,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function spec(string $domain, array $config = [], int $timeout = 5): MonitorSpec
    {
        return MonitorSpecFactory::of(MonitorType::Domain, $config + [
            'domain' => $domain,
            'expiry_days' => [30, 14, 7, 1],
            'degraded_below_days' => 7,
        ], intervalSeconds: 3600, retryIntervalSeconds: 3600, timeoutSeconds: $timeout);
    }

    private function probe(DomainExpiryService $service, string $domain, int $timeout = 5): CheckOutcome
    {
        return (new DomainChecker($service))->check($this->spec($domain, timeout: $timeout), new NullCancellation);
    }

    public function test_rdap_expiry_is_parsed_and_cached_for_a_day(): void
    {
        $clock = new FakeClock(CarbonImmutable::parse('2026-11-06 12:00:00', 'UTC'));
        $this->rdap()->respond('/domain/example.com', 200, RdapFixtureServer::domain('example.com', '2027-02-14T12:00:00Z'));
        $service = $this->service($clock);

        $outcome = $this->probe($service, 'example.com');
        $this->assertSame(MonitorStatus::Up, $outcome->status, $outcome->message);
        $this->assertSame('', $outcome->message);
        $this->assertSame([
            'domain.expires_at' => '2027-02-14T12:00:00Z',
            'domain.days_left' => 100,
            'domain.registrar' => 'Example Registrar, Inc.',
            'domain.source' => 'rdap',
        ], $outcome->facts);
        $this->assertSame(['domain.expiry_band' => 'ok'], $outcome->watch);

        $row = DomainLookup::query()->findOrFail('example.com');
        $this->assertSame('rdap', $row->source);
        $this->assertSame('ok', $row->status);
        $this->assertSame('2027-02-14 12:00:00', $row->expires_at?->utc()->format('Y-m-d H:i:s'));

        $clock->advance(23 * 3600);
        $this->assertSame(MonitorStatus::Up, $this->probe($service, 'example.com')->status);
        // A second worker process serves the row from the database without asking the registry.
        $this->assertSame(99, $this->probe($this->service($clock), 'example.com')->facts['domain.days_left']);
        $this->assertSame(['/domain/example.com'], $this->rdap()->requests);

        $clock->advance(2 * 3600);
        $this->probe($service, 'example.com');
        $this->assertSame(['/domain/example.com', '/domain/example.com'], $this->rdap()->requests);
    }

    public function test_label_stripping_never_queries_the_tld(): void
    {
        $this->rdap()->respond('/domain/example.co.uk', 200, RdapFixtureServer::domain('example.co.uk', '2030-01-01T00:00:00Z'));
        $service = $this->service();

        $expiry = $service->lookup('www.shop.example.co.uk', new NullCancellation);
        $this->assertSame('example.co.uk', $expiry->domain);
        $this->assertSame('ok', $expiry->status);
        $this->assertSame(['/domain/example.co.uk'], $this->rdap()->requests);

        foreach (['co.uk', 'uk', '192.0.2.1', '2001:db8::1', 'localhost', 'host.invalid'] as $host) {
            $this->assertNull($service->peek($host), $host);

            try {
                $service->lookup($host, new NullCancellation);
                $this->fail("{$host} was looked up");
            } catch (NotRegistrableDomain) {
                // Expected.
            }

            $outcome = $this->probe($service, $host);
            $this->assertSame(MonitorStatus::Down, $outcome->status, $host);
            $this->assertSame('Not a registrable domain', $outcome->message);
        }
        $this->assertSame(['/domain/example.co.uk'], $this->rdap()->requests);
    }

    public function test_unpublished_expiry_is_up_not_down(): void
    {
        $this->whois()->answer('intragile.hu', (string) file_get_contents(base_path('tests/Fixtures/whois/hu.txt')));
        $service = $this->service(whoisServers: ['hu' => $this->whois()->server()]);

        $outcome = $this->probe($service, 'intragile.hu');
        $this->assertSame(MonitorStatus::Up, $outcome->status);
        $this->assertSame('Expiry date not published by the registry', $outcome->message);
        $this->assertSame([
            'domain.expires_at' => null,
            'domain.days_left' => null,
            'domain.registrar' => null,
            'domain.source' => 'whois',
        ], $outcome->facts);
        $this->assertSame([], $outcome->watch);
        $this->assertSame('unknown', DomainLookup::query()->findOrFail('intragile.hu')->status);
        $this->assertSame([], $this->rdap()->requests);
    }

    public function test_whois_server_comes_from_iana_and_is_cached(): void
    {
        $whois = $this->whois();
        $whois->answer('hu', (string) file_get_contents(base_path('tests/Fixtures/whois/iana-hu.txt')));
        $whois->answer('de', "% IANA WHOIS server\n\ndomain: DE\n");
        $whois->answer('example.de', "Registry Expiry Date: 2032-01-01T00:00:00Z\n");
        $service = $this->service(bootstrap: []);

        // The referred server whois.nic.hu is not in the stub resolver, so the lookup fails without the network.
        $hu = $service->lookup('example.hu', new NullCancellation);
        $this->assertSame('error', $hu->status);
        $this->assertSame('whois.nic.hu', DomainLookup::query()->findOrFail('_whois.hu')->registrar);

        // Without a referral the registry's conventional server name is used.
        $de = $this->service(bootstrap: [])->lookup('example.de', new NullCancellation);
        $this->assertSame('2032-01-01T00:00:00Z', $de->expiresAt?->toIso8601ZuluString());
        $this->assertSame('whois.nic.de', DomainLookup::query()->findOrFail('_whois.de')->registrar);

        // A later process reads the cached referral instead of asking IANA again.
        $this->service(bootstrap: [])->lookup('other.de', new NullCancellation);
        $this->assertSame(['hu', 'de', 'example.de', 'other.de'], $whois->queries);
    }

    public function test_lookup_error_is_up_and_retried_after_an_hour(): void
    {
        $clock = new FakeClock(CarbonImmutable::parse('2026-11-06 12:00:00', 'UTC'));
        $this->rdap()->respond('/domain/example.com', 503, '');
        $service = $this->service($clock);

        $outcome = $this->probe($service, 'example.com');
        $this->assertSame(MonitorStatus::Up, $outcome->status);
        $this->assertSame('Lookup failed: HTTP 503', $outcome->message);
        $this->assertSame('rdap', $outcome->facts['domain.source']);
        $this->assertSame('error', DomainLookup::query()->findOrFail('example.com')->status);

        $clock->advance(59 * 60);
        $this->assertSame('Lookup failed: HTTP 503', $this->probe($service, 'example.com')->message);
        $this->assertCount(1, $this->rdap()->requests);

        $this->rdap()->respond('/domain/example.com', 429, '');
        $clock->advance(2 * 60);
        $this->assertSame('Lookup failed: HTTP 429', $this->probe($service, 'example.com')->message);
        $this->assertCount(2, $this->rdap()->requests);

        $this->rdap()->respond('/domain/example.com', 200, RdapFixtureServer::domain('example.com', '2030-01-01T00:00:00Z'));
        $clock->advance(61 * 60);
        $recovered = $this->probe($service, 'example.com');
        $this->assertSame(MonitorStatus::Up, $recovered->status);
        $this->assertSame('', $recovered->message);
        $this->assertCount(3, $this->rdap()->requests);
    }

    public function test_expired_domain_is_down_and_band_watched(): void
    {
        $clock = new FakeClock(CarbonImmutable::parse('2026-11-06 12:00:00', 'UTC'));
        $service = $this->service($clock);
        $monitor = Monitor::factory()->domain('example.com')->create();
        $recorder = $this->app->make(ResultRecorder::class);
        $record = function (string $expires) use ($clock, $service, $monitor, $recorder): CheckOutcome {
            $this->rdap()->respond('/domain/example.com', 200, RdapFixtureServer::domain('example.com', $expires));
            $spec = MonitorSpec::fromModel($monitor->fresh() ?? $monitor);
            $outcome = (new DomainChecker($service))->check($spec, new NullCancellation);
            $recorder->record([[$spec, $outcome, $clock->now(), 5]]);
            $clock->advance(25 * 3600);

            return $outcome;
        };

        $healthy = $record('2026-12-31T12:00:00Z');
        $this->assertSame(MonitorStatus::Up, $healthy->status);
        $this->assertSame('ok', $healthy->watch['domain.expiry_band']);

        // 2026-11-07 13:00, three days and 23 hours before expiry.
        $expiring = $record('2026-11-11T12:00:00Z');
        $this->assertSame(MonitorStatus::Degraded, $expiring->status);
        $this->assertSame('Domain expires in 3 days', $expiring->message);
        $this->assertSame('7', $expiring->watch['domain.expiry_band']);

        // 2026-11-08 14:00, one hour after expiry.
        $expired = $record('2026-11-08T13:00:00Z');
        $this->assertSame(MonitorStatus::Down, $expired->status);
        $this->assertSame('Domain expired', $expired->message);
        $this->assertSame(-1, $expired->facts['domain.days_left']);
        $this->assertSame('expired', $expired->watch['domain.expiry_band']);

        $events = MonitorEvent::query()->where('kind', MonitorEvent::KIND_CHANGED)->orderBy('id')->get();
        $this->assertSame(['domain.expiry_band', 'domain.expiry_band'], $events->pluck('key')->all());
    }

    public function test_a_cached_expiry_that_has_passed_is_confirmed_before_reporting_down(): void
    {
        $clock = new FakeClock(CarbonImmutable::parse('2026-11-06 12:00:00', 'UTC'));
        $this->rdap()->respond('/domain/example.com', 200, RdapFixtureServer::domain('example.com', '2026-11-06T18:00:00Z'));
        $service = $this->service($clock);
        $this->assertSame(MonitorStatus::Degraded, $this->probe($service, 'example.com')->status);

        // The registry renewed the domain at expiry; the cached date is not trusted once it has passed.
        $this->rdap()->respond('/domain/example.com', 200, RdapFixtureServer::domain('example.com', '2027-11-06T18:00:00Z'));
        $clock->advance(7 * 3600);
        $this->assertSame(MonitorStatus::Up, $this->probe($service, 'example.com')->status);
        $this->assertCount(2, $this->rdap()->requests);
    }

    public function test_rdap_not_found_is_down_only_when_the_registry_answers(): void
    {
        $this->otherRdap = new RdapFixtureServer;
        $other = 'http://other-rdap.test:'.$this->otherRdap->port();
        $this->rdap()->respond('/domain/gone.com', 404, '{"errorCode":404}');
        $this->rdap()->respond('/domain/moved.com', 302, '', headers: ['location' => $other.'/domain/moved.com']);
        $this->rdap()->respond('/domain/elsewhere.com', 302, '', headers: ['location' => $other.'/domain/elsewhere.com']);
        $this->otherRdap->respond('/domain/elsewhere.com', 200, RdapFixtureServer::domain('elsewhere.com', '2030-01-01T00:00:00Z', 'Thin Registrar'));
        $service = $this->service();

        $gone = $this->probe($service, 'gone.com');
        $this->assertSame(MonitorStatus::Down, $gone->status);
        $this->assertSame('Domain is not registered', $gone->message);
        $this->assertSame('not_found', DomainLookup::query()->findOrFail('gone.com')->status);

        $moved = $this->probe($service, 'moved.com');
        $this->assertSame(MonitorStatus::Up, $moved->status);
        $this->assertSame('Lookup failed: HTTP 404', $moved->message);

        $elsewhere = $this->probe($service, 'elsewhere.com');
        $this->assertSame(MonitorStatus::Up, $elsewhere->status, $elsewhere->message);
        $this->assertSame('Thin Registrar', $elsewhere->facts['domain.registrar']);
    }

    public function test_expiry_dates_far_in_the_future_are_stored(): void
    {
        $this->rdap()->respond('/domain/example.com', 200, RdapFixtureServer::domain('example.com', '2099-12-31T23:59:59Z'));

        $this->assertSame(MonitorStatus::Up, $this->probe($this->service(), 'example.com')->status);
        $this->assertSame('2099-12-31 23:59:59', DomainLookup::query()->findOrFail('example.com')->expires_at?->utc()->format('Y-m-d H:i:s'));
    }

    public function test_invalid_rdap_answers_are_lookup_errors(): void
    {
        $this->rdap()->respond('/domain/broken.com', 200, '{"events": "nope"');
        $this->rdap()->respond('/domain/huge.com', 200, '{"pad": "'.str_repeat('x', 2 * 1048576).'"}');
        $this->rdap()->respond('/domain/noexpiry.com', 200, RdapFixtureServer::domain('noexpiry.com', null));
        $this->rdap()->respond('/domain/badexpiry.com', 200, RdapFixtureServer::domain('badexpiry.com', 'next tuesday'));
        $service = $this->service();

        $this->assertSame('Lookup failed: Invalid RDAP response', $this->probe($service, 'broken.com')->message);
        $this->assertSame('Lookup failed: Invalid RDAP response', $this->probe($service, 'huge.com')->message);
        $this->assertSame('Expiry date not published by the registry', $this->probe($service, 'noexpiry.com')->message);
        $this->assertSame('Expiry date not published by the registry', $this->probe($service, 'badexpiry.com')->message);
    }

    public function test_a_slow_registry_is_bounded_by_the_check_timeout_and_the_answer_is_kept(): void
    {
        $this->rdap()->respond('/domain/example.com', 200, RdapFixtureServer::domain('example.com', '2030-01-01T00:00:00Z'), delay: 2.0);
        $service = $this->service();

        $started = microtime(true);
        $outcome = $this->probe($service, 'example.com', timeout: 1);
        $this->assertLessThan(1.8, microtime(true) - $started);
        $this->assertSame(MonitorStatus::Up, $outcome->status);
        $this->assertSame('Lookup failed: Timed out after 1 s', $outcome->message);

        // The lookup carries on in the background and the next check uses its answer.
        delay(1.5);
        $this->assertSame('2030-01-01T00:00:00Z', $this->probe($service, 'example.com')->facts['domain.expires_at']);
        $this->assertCount(1, $this->rdap()->requests);
    }

    public function test_whois_answers_are_capped_and_bounded(): void
    {
        $whois = $this->whois();
        $whois->answer('big.hu', str_repeat("% filler line of a very chatty registry\n", 3000)."Expiry Date: 2031-01-01\n");
        $whois->answer('slow.hu', "Expiry Date: 2031-01-01\n", delay: 3.0);
        $service = $this->service(whoisServers: ['hu' => $whois->server()], lookupTimeout: 1.0);

        $big = $service->lookup('big.hu', new NullCancellation);
        $this->assertSame('unknown', $big->status);
        $this->assertNull($big->expiresAt);

        $started = microtime(true);
        $slow = $service->lookup('slow.hu', new NullCancellation);
        $this->assertLessThan(2.0, microtime(true) - $started);
        $this->assertSame('error', $slow->status);
        $this->assertSame('Timed out after 1 s', $slow->error);
    }

    public function test_concurrent_lookups_for_one_domain_share_a_single_request(): void
    {
        $this->rdap()->respond('/domain/example.com', 200, RdapFixtureServer::domain('example.com', '2030-01-01T00:00:00Z'), delay: 0.3);
        $service = $this->service();

        $this->assertNull($service->peek('www.example.com'));
        $futures = [];
        foreach (['example.com', 'www.example.com', 'api.example.com'] as $host) {
            $futures[] = async(fn () => $service->lookup($host, new NullCancellation));
        }
        $results = await($futures);

        $this->assertCount(1, $this->rdap()->requests);
        foreach ($results as $result) {
            $this->assertInstanceOf(DomainExpiry::class, $result);
            $this->assertSame('2030-01-01T00:00:00Z', $result->expiresAt?->toIso8601ZuluString());
        }
    }

    public function test_https_check_serves_domain_facts_from_cache_only(): void
    {
        $http = $this->http = new HttpFixtureServer;
        $this->rdap()->respond('/domain/example.com', 200, RdapFixtureServer::domain('example.com', '2030-01-01T00:00:00Z'), delay: 1.0);
        $service = $this->service();
        $checker = new HttpChecker(new ClientFactory($this->connector()), null, new DomainFacts($service, new SystemClock));
        $spec = fn (bool $domainExpiry): MonitorSpec => MonitorSpecFactory::of(MonitorType::Http, [
            'url' => 'http://www.example.com:'.$http->port().'/fast',
            'accepted_status' => ['200-299'],
            'max_redirects' => 0,
            'tls_checks' => false,
            'domain_expiry' => $domainExpiry,
        ], timeoutSeconds: 5);

        $this->assertArrayNotHasKey('domain.expires_at', $checker->check($spec(false), new NullCancellation)->facts);
        delay(1.2);
        $this->assertSame([], $this->rdap()->requests);

        $started = microtime(true);
        $miss = $checker->check($spec(true), new NullCancellation);
        $this->assertLessThan(0.5, microtime(true) - $started);
        $this->assertSame(MonitorStatus::Up, $miss->status, $miss->message);
        $this->assertArrayNotHasKey('domain.expires_at', $miss->facts);

        delay(1.3);
        $hit = $checker->check($spec(true), new NullCancellation);
        $this->assertSame('2030-01-01T00:00:00Z', $hit->facts['domain.expires_at']);
        $this->assertSame('rdap', $hit->facts['domain.source']);
        $this->assertSame('ok', $hit->watch['domain.expiry_band']);
        $this->assertCount(1, $this->rdap()->requests);
    }

    public function test_background_lookup_failures_are_logged_and_never_leak(): void
    {
        $log = Log::spy();
        $service = $this->service(bootstrap: ['com' => 'http://[broken/']);

        $this->assertNull($service->peek('example.com'));
        delay(0.2);

        $this->assertSame('error', DomainLookup::query()->findOrFail('example.com')->status);
        $log->shouldHaveReceived('warning')->once();
    }

    public function test_domain_type_is_registered(): void
    {
        $this->assertInstanceOf(DomainChecker::class, $this->app->make(CheckerRegistry::class)->for(MonitorType::Domain));
        $this->assertInstanceOf(DomainExpiryService::class, $this->app->make(DomainExpiryService::class));
        $this->assertSame($this->app->make(DomainExpiryService::class), $this->app->make(DomainExpiryService::class));
    }
}
