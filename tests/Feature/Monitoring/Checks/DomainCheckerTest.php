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
use App\Monitoring\Worker\MonitorLoader;
use App\Monitoring\Worker\OutcomeBuffer;
use App\Monitoring\Worker\SystemClock;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Revolt\EventLoop;
use Tests\Support\FakeClock;
use Tests\Support\HttpFixtureServer;
use Tests\Support\MonitorSpecFactory;
use Tests\Support\RdapFixtureServer;
use Tests\Support\StubDnsResolver;
use Tests\Support\TestCertificates;
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

    /** @var list<RdapFixtureServer> */
    private array $otherServers = [];

    private ?WhoisFixtureServer $whois = null;

    private ?HttpFixtureServer $http = null;

    private ?TestCertificates $certificates = null;

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
        foreach ($this->otherServers as $server) {
            $server->stop();
        }
        $this->whois?->stop();
        $this->http?->stop();
        $this->certificates?->cleanup();
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
     * @param  ?Closure(): float  $jitter  No jitter unless given.
     */
    private function service(?Clock $clock = null, ?array $bootstrap = null, array $whoisServers = [], float $lookupTimeout = 10.0, ?Closure $jitter = null, ?string $caFile = null): DomainExpiryService
    {
        $base = 'http://rdap.test:'.$this->rdap()->port().'/';
        $whoisPort = (int) substr($this->whois()->server(), strrpos($this->whois()->server(), ':') + 1);

        return new DomainExpiryService(
            $this->app->make(RegistrableDomains::class),
            RdapBootstrap::fromArray($bootstrap ?? ['com' => $base, 'uk' => $base]),
            new RdapClient($this->connector(), $caFile),
            new WhoisClient($this->connector(), $whoisPort),
            $clock ?? new SystemClock,
            $whoisServers,
            'whois.iana.test',
            $lookupTimeout,
            $jitter ?? static fn (): float => 1.0,
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

    /**
     * A second worker process: a new service preloaded from the database, as the monitor loader does.
     *
     * @param  list<string>  $domains
     * @param  array<string, string>|null  $bootstrap
     */
    private function restarted(array $domains, ?Clock $clock = null, ?array $bootstrap = null): DomainExpiryService
    {
        $service = $this->service($clock, $bootstrap);
        $service->warm(array_map(fn (string $domain): MonitorSpec => $this->spec($domain), $domains));

        return $service;
    }

    private function httpSpec(string $url, bool $domainExpiry = true): MonitorSpec
    {
        return MonitorSpecFactory::of(MonitorType::Http, [
            'url' => $url,
            'accepted_status' => ['200-299'],
            'max_redirects' => 0,
            'tls_checks' => false,
            'domain_expiry' => $domainExpiry,
        ], timeoutSeconds: 5);
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

        $this->assertSame(1, $service->pendingWrites());
        $this->assertTrue($service->flushPending());
        $this->assertSame(0, $service->pendingWrites());
        $row = DomainLookup::query()->findOrFail('example.com');
        $this->assertSame('rdap', $row->source);
        $this->assertSame('ok', $row->status);
        $this->assertSame('2027-02-14 12:00:00', $row->expires_at?->utc()->format('Y-m-d H:i:s'));

        $clock->advance(23 * 3600);
        $this->assertSame(MonitorStatus::Up, $this->probe($service, 'example.com')->status);
        // A second worker process serves the preloaded row without asking the registry.
        $this->assertSame(99, $this->probe($this->restarted(['example.com'], $clock), 'example.com')->facts['domain.days_left']);
        $this->assertSame(['/domain/example.com'], $this->rdap()->requests);

        $clock->advance(2 * 3600);
        $this->probe($service, 'example.com');
        $this->assertSame(['/domain/example.com', '/domain/example.com'], $this->rdap()->requests);
    }

    public function test_checks_never_query_the_database(): void
    {
        $this->rdap()->respond('/domain/example.com', 200, RdapFixtureServer::domain('example.com', '2030-01-01T00:00:00Z'));
        $this->rdap()->respond('/domain/other.com', 200, RdapFixtureServer::domain('other.com', '2031-01-01T00:00:00Z'));
        DomainLookup::query()->insert((new DomainExpiry('cached.com', 'rdap', 'ok', CarbonImmutable::parse('2032-01-01', 'UTC'), null, null, CarbonImmutable::now()))->toRow());

        $service = $this->restarted(['cached.com', 'example.com']);
        $http = $this->http = new HttpFixtureServer;
        $checker = new HttpChecker(new ClientFactory($this->connector()), null, new DomainFacts($service, new SystemClock));

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->assertSame('2032-01-01T00:00:00Z', $this->probe($service, 'cached.com')->facts['domain.expires_at']);
        $this->assertSame('2030-01-01T00:00:00Z', $this->probe($service, 'example.com')->facts['domain.expires_at']);
        $this->assertSame('Domain is not registered', $this->probe($service, 'gone.co.uk')->message);
        $checker->check($this->httpSpec('http://www.example.com:'.$http->port().'/fast'), new NullCancellation);
        $this->assertNull($service->peek('other.com'));
        delay(0.3);
        $this->assertSame(0, $queries, 'A check queried the database');

        $this->assertTrue($service->flushPending());
        $this->assertSame(1, $queries, 'Pending rows are written in one statement');
        $this->assertSame(['cached.com', 'example.com', 'gone.co.uk', 'other.com'], DomainLookup::query()->orderBy('domain')->pluck('domain')->all());
    }

    public function test_pending_rows_survive_a_failed_write(): void
    {
        $this->rdap()->respond('/domain/example.com', 200, RdapFixtureServer::domain('example.com', '2030-01-01T00:00:00Z'));
        $service = $this->service();
        $this->probe($service, 'example.com');

        $default = DB::getDefaultConnection();
        config(['database.connections.unreachable' => ['driver' => 'sqlite', 'database' => '/nonexistent/intrawatch.sqlite', 'prefix' => '']]);
        DB::setDefaultConnection('unreachable');
        try {
            $this->assertFalse($service->flushPending());
        } finally {
            DB::setDefaultConnection($default);
        }

        $this->assertSame(1, $service->pendingWrites());
        $this->assertTrue($service->flushPending());
        $this->assertSame('ok', DomainLookup::query()->findOrFail('example.com')->status);
    }

    public function test_the_loader_preloads_and_the_buffer_writes_domain_lookups(): void
    {
        DomainLookup::query()->insert((new DomainExpiry('example.com', 'rdap', 'ok', CarbonImmutable::parse('2032-01-01', 'UTC'), null, null, CarbonImmutable::now()))->toRow());
        Monitor::factory()->domain('example.com')->create();
        $service = $this->service();
        $clock = new SystemClock;

        $this->assertNull($service->cached('example.com'));
        (new MonitorLoader($clock, $service))->loadShard(0, 1);
        $this->assertSame('2032-01-01T00:00:00Z', $service->cached('www.example.com')?->expiresAt?->toIso8601ZuluString());

        $this->rdap()->respond('/domain/other.com', 200, RdapFixtureServer::domain('other.com', '2031-01-01T00:00:00Z'));
        $service->lookup('other.com', new NullCancellation);
        (new OutcomeBuffer($this->app->make(ResultRecorder::class), $clock, afterFlush: $service->flushPending(...)))->drain();
        $this->assertSame('ok', DomainLookup::query()->findOrFail('other.com')->status);
    }

    public function test_label_stripping_never_queries_the_tld(): void
    {
        $this->rdap()->respond('/domain/example.co.uk', 200, RdapFixtureServer::domain('example.co.uk', '2030-01-01T00:00:00Z'));
        $service = $this->service();

        $expiry = $service->lookup('www.shop.example.co.uk', new NullCancellation);
        $this->assertSame('example.co.uk', $expiry->domain);
        $this->assertSame('ok', $expiry->status);
        $this->assertSame(['/domain/example.co.uk'], $this->rdap()->requests);

        foreach (['co.uk', 'uk', '192.0.2.1', '2001:db8::1', 'localhost', 'host.invalid', '_whois.hu', 'a_b.example.com'] as $host) {
            $this->assertNull($service->peek($host), $host);

            try {
                $service->lookup($host, new NullCancellation);
                $this->fail("{$host} was looked up");
            } catch (NotRegistrableDomain) {
                // Expected.
            }

            // Validation rejects these names, so at run time they only follow a list change: never down.
            $outcome = $this->probe($service, $host);
            $this->assertSame(MonitorStatus::Up, $outcome->status, $host);
            $this->assertSame('Lookup failed: Not a registrable domain', $outcome->message);
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
        $service->flushPending();
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

        // Without a referral the registry's conventional server name is used.
        $de = $service->lookup('example.de', new NullCancellation);
        $this->assertSame('2032-01-01T00:00:00Z', $de->expiresAt?->toIso8601ZuluString());

        $service->flushPending();
        $referred = DomainLookup::query()->findOrFail('whois:hu');
        $this->assertSame(['whois.nic.hu', 'ok'], [$referred->registrar, $referred->status]);
        $guessed = DomainLookup::query()->findOrFail('whois:de');
        $this->assertSame(['whois.nic.de', 'unknown'], [$guessed->registrar, $guessed->status]);

        // A later process reads the preloaded discovery instead of asking IANA again.
        $this->restarted(['other.de'], bootstrap: [])->lookup('other.de', new NullCancellation);
        $this->assertSame(['hu', 'de', 'example.de', 'other.de'], $whois->queries);
    }

    public function test_not_found_from_a_guessed_whois_server_is_not_authoritative(): void
    {
        $whois = $this->whois();
        $whois->answer('de', "% IANA WHOIS server\n\ndomain: DE\n");
        $whois->answer('gone.de', (string) file_get_contents(base_path('tests/Fixtures/whois/not-found-de.txt')));

        $guessed = $this->probe($this->service(bootstrap: []), 'gone.de');
        $this->assertSame(MonitorStatus::Up, $guessed->status);
        $this->assertSame('Expiry date not published by the registry', $guessed->message);

        $configured = $this->probe($this->service(bootstrap: [], whoisServers: ['de' => $whois->server()]), 'gone.de');
        $this->assertSame(MonitorStatus::Down, $configured->status);
        $this->assertSame('Domain is not registered', $configured->message);
    }

    public function test_whois_discovery_is_shared_by_concurrent_lookups(): void
    {
        $whois = $this->whois();
        $whois->answer('de', "refer: whois.nic.de\n", delay: 0.2);
        $domains = ['a.de', 'b.de', 'c.de', 'd.de', 'e.de'];
        foreach ($domains as $domain) {
            $whois->answer($domain, "Expiry Date: 2031-01-01\n");
        }
        $service = $this->service(bootstrap: []);

        $results = await(array_map(fn (string $domain) => async(fn () => $service->lookup($domain, new NullCancellation)), $domains));

        $this->assertCount(5, $results);
        $this->assertSame(1, count(array_filter($whois->queries, fn (string $query): bool => $query === 'de')));
    }

    public function test_concurrency_per_registry_server_is_limited(): void
    {
        $domains = [];
        for ($i = 0; $i < 10; $i++) {
            $domains[] = $domain = "site{$i}.com";
            $this->rdap()->respond("/domain/{$domain}", 200, RdapFixtureServer::domain($domain, '2030-01-01T00:00:00Z'), delay: 0.1);
        }
        $service = $this->service(lookupTimeout: 1.0);

        foreach ($domains as $domain) {
            $this->assertNull($service->peek($domain));
        }
        delay(1.0);

        $this->assertCount(10, $this->rdap()->requests);
        $this->assertSame(DomainExpiryService::REGISTRY_CONCURRENCY, $this->rdap()->maxConcurrent);
        // Waiting for a slot does not count against a query's deadline.
        foreach ($domains as $domain) {
            $this->assertSame('ok', $service->cached($domain)?->status, $domain);
        }
    }

    public function test_cache_lifetimes_are_jittered(): void
    {
        $clock = new FakeClock(CarbonImmutable::parse('2026-11-06 12:00:00', 'UTC'));
        $domains = [];
        $rows = [];
        for ($i = 0; $i < 100; $i++) {
            $domains[] = $domain = "site{$i}.com";
            $rows[] = (new DomainExpiry($domain, 'rdap', 'ok', CarbonImmutable::parse('2032-01-01', 'UTC'), null, null, $clock->now()->subHours(23)))->toRow();
        }
        DomainLookup::query()->insert($rows);

        $service = new DomainExpiryService(
            $this->app->make(RegistrableDomains::class),
            RdapBootstrap::fromArray(['com' => 'http://rdap.test:'.$this->rdap()->port().'/']),
            new RdapClient($this->connector()),
            new WhoisClient($this->connector()),
            $clock,
        );
        $service->warm(array_map(fn (string $domain): MonitorSpec => $this->spec($domain), $domains));

        // 23 h old rows with a 24 h ± 10 % lifetime: some are still fresh, some are not.
        $fresh = count(array_filter($domains, fn (string $domain): bool => $service->cached($domain) !== null));
        $this->assertGreaterThan(5, $fresh);
        $this->assertLessThan(95, $fresh);

        $clock->advance(3.5 * 3600);
        $this->assertSame([], array_values(array_filter($domains, fn (string $domain): bool => $service->cached($domain) !== null)));
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
        $service->flushPending();
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
        $this->otherServers[] = $otherServer = new RdapFixtureServer;
        $other = 'http://other-rdap.test:'.$otherServer->port();
        $this->rdap()->respond('/domain/gone.com', 404, '{"errorCode":404}');
        $this->rdap()->respond('/domain/moved.com', 302, '', headers: ['location' => $other.'/domain/moved.com']);
        $this->rdap()->respond('/domain/elsewhere.com', 302, '', headers: ['location' => $other.'/domain/elsewhere.com']);
        $this->rdap()->respond('/domain/relative.com', 301, '', headers: ['location' => '/v2/domain/relative.com']);
        $this->rdap()->respond('/v2/domain/relative.com', 200, RdapFixtureServer::domain('relative.com', '2030-01-01T00:00:00Z'));
        $this->rdap()->respond('/domain/loop.com', 302, '', headers: ['location' => '/domain/loop.com']);
        $otherServer->respond('/domain/elsewhere.com', 200, RdapFixtureServer::domain('elsewhere.com', '2030-01-01T00:00:00Z', 'Thin Registrar'));
        $service = $this->service();

        $gone = $this->probe($service, 'gone.com');
        $this->assertSame(MonitorStatus::Down, $gone->status);
        $this->assertSame('Domain is not registered', $gone->message);

        $moved = $this->probe($service, 'moved.com');
        $this->assertSame(MonitorStatus::Up, $moved->status);
        $this->assertSame('Lookup failed: HTTP 404', $moved->message);

        $elsewhere = $this->probe($service, 'elsewhere.com');
        $this->assertSame(MonitorStatus::Up, $elsewhere->status, $elsewhere->message);
        $this->assertSame('Thin Registrar', $elsewhere->facts['domain.registrar']);

        $this->assertSame('2030-01-01T00:00:00Z', $this->probe($service, 'relative.com')->facts['domain.expires_at']);
        $this->assertSame('Lookup failed: Too many redirects', $this->probe($service, 'loop.com')->message);
    }

    public function test_rdap_redirects_never_leave_https(): void
    {
        $this->certificates = new TestCertificates(['rdap.test']);
        $this->otherServers[] = $secure = new RdapFixtureServer($this->certificates);
        $secure->respond('/domain/example.com', 302, '', headers: ['location' => 'http://rdap.test:'.$this->rdap()->port().'/domain/example.com']);
        $secure->respond('/domain/stays.com', 302, '', headers: ['location' => '/next/stays.com']);
        $secure->respond('/next/stays.com', 200, RdapFixtureServer::domain('stays.com', '2030-01-01T00:00:00Z'));
        $this->rdap()->respond('/domain/example.com', 200, RdapFixtureServer::domain('example.com', '2030-01-01T00:00:00Z'));
        $service = $this->service(bootstrap: ['com' => 'https://rdap.test:'.$secure->port().'/'], caFile: $this->certificates->caFile);

        $this->assertSame('Lookup failed: Insecure redirect', $this->probe($service, 'example.com')->message);
        $this->assertSame([], $this->rdap()->requests);
        $this->assertSame('2030-01-01T00:00:00Z', $this->probe($service, 'stays.com')->facts['domain.expires_at']);
    }

    public function test_registry_traffic_never_reaches_private_networks(): void
    {
        $policy = NetworkPolicy::forRegistries();
        foreach (['127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '100.64.0.1', '169.254.169.254', '::1', 'fd00::1', 'fe80::1', '::ffff:10.0.0.1'] as $ip) {
            $this->assertFalse($policy->allows($ip), $ip);
        }
        foreach (['93.184.216.34', '2606:4700::1'] as $ip) {
            $this->assertTrue($policy->allows($ip), $ip);
        }

        $this->rdap()->respond('/domain/example.com', 200, RdapFixtureServer::domain('example.com', '2030-01-01T00:00:00Z'));
        $strict = new GuardedConnector(new StubDnsResolver(self::HOSTS), $policy);
        $service = new DomainExpiryService(
            $this->app->make(RegistrableDomains::class),
            RdapBootstrap::fromArray(['com' => 'http://rdap.test:'.$this->rdap()->port().'/']),
            new RdapClient($strict),
            new WhoisClient($strict),
            new SystemClock,
        );

        $this->assertSame('Lookup failed: Blocked target', $this->probe($service, 'example.com')->message);
        $this->assertSame([], $this->rdap()->requests);
    }

    public function test_expiry_dates_far_in_the_future_are_stored(): void
    {
        $this->rdap()->respond('/domain/example.com', 200, RdapFixtureServer::domain('example.com', '2099-12-31T23:59:59Z'));
        $service = $this->service();

        $this->assertSame(MonitorStatus::Up, $this->probe($service, 'example.com')->status);
        $this->assertTrue($service->flushPending());
        $this->assertSame('2099-12-31 23:59:59', DomainLookup::query()->findOrFail('example.com')->expires_at?->utc()->format('Y-m-d H:i:s'));
    }

    public function test_invalid_rdap_answers_are_lookup_errors(): void
    {
        $this->rdap()->respond('/domain/broken.com', 200, '{"events": "nope"');
        $this->rdap()->respond('/domain/huge.com', 200, '{"pad": "'.str_repeat('x', 2 * 1048576).'"}');
        $this->rdap()->respond('/domain/noexpiry.com', 200, RdapFixtureServer::domain('noexpiry.com', null));
        $this->rdap()->respond('/domain/badexpiry.com', 200, RdapFixtureServer::domain('badexpiry.com', 'next tuesday'));
        $this->rdap()->respond('/domain/badredirect.com', 302, '', headers: ['location' => 'http://[::1']);
        $service = $this->service();

        $this->assertSame('Lookup failed: Invalid RDAP response', $this->probe($service, 'broken.com')->message);
        $this->assertSame('Lookup failed: Invalid RDAP response', $this->probe($service, 'huge.com')->message);
        $this->assertSame('Expiry date not published by the registry', $this->probe($service, 'noexpiry.com')->message);
        $this->assertSame('Expiry date not published by the registry', $this->probe($service, 'badexpiry.com')->message);
        $this->assertSame('Lookup failed: Invalid redirect', $this->probe($service, 'badredirect.com')->message);
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
        $clock = new FakeClock(CarbonImmutable::parse('2026-11-06 12:00:00', 'UTC'));
        $http = $this->http = new HttpFixtureServer;
        $this->rdap()->respond('/domain/example.com', 200, RdapFixtureServer::domain('example.com', '2030-01-01T00:00:00Z'), delay: 1.0);
        $service = $this->service($clock);
        $checker = new HttpChecker(new ClientFactory($this->connector()), null, new DomainFacts($service, $clock));
        $url = 'http://www.example.com:'.$http->port().'/fast';

        $this->assertArrayNotHasKey('domain.expires_at', $checker->check($this->httpSpec($url, false), new NullCancellation)->facts);
        delay(1.2);
        $this->assertSame([], $this->rdap()->requests);

        $started = microtime(true);
        $miss = $checker->check($this->httpSpec($url), new NullCancellation);
        $this->assertLessThan(0.5, microtime(true) - $started);
        $this->assertSame(MonitorStatus::Up, $miss->status, $miss->message);
        $this->assertArrayNotHasKey('domain.expires_at', $miss->facts);

        delay(1.3);
        $hit = $checker->check($this->httpSpec($url), new NullCancellation);
        $this->assertSame('2030-01-01T00:00:00Z', $hit->facts['domain.expires_at']);
        $this->assertSame('rdap', $hit->facts['domain.source']);
        $this->assertSame('ok', $hit->watch['domain.expiry_band']);
        $this->assertCount(1, $this->rdap()->requests);

        // Once stale, the old answer is still served while the refresh runs in the background.
        $clock->advance(25 * 3600);
        $started = microtime(true);
        $refreshing = $checker->check($this->httpSpec($url), new NullCancellation);
        $this->assertLessThan(0.5, microtime(true) - $started);
        $this->assertSame('2030-01-01T00:00:00Z', $refreshing->facts['domain.expires_at']);
        delay(1.3);
        $this->assertCount(2, $this->rdap()->requests);
    }

    public function test_background_lookup_failures_are_logged_and_never_leak(): void
    {
        $log = Log::spy();
        $service = $this->service(bootstrap: ['com' => 'http://[broken/']);

        $this->assertNull($service->peek('example.com'));
        delay(0.2);

        $this->assertSame('error', $service->cached('example.com')?->status);
        $log->shouldHaveReceived('warning')->once();
    }

    public function test_domain_type_is_registered(): void
    {
        $this->assertInstanceOf(DomainChecker::class, $this->app->make(CheckerRegistry::class)->for(MonitorType::Domain));
        $this->assertInstanceOf(DomainExpiryService::class, $this->app->make(DomainExpiryService::class));
        $this->assertSame($this->app->make(DomainExpiryService::class), $this->app->make(DomainExpiryService::class));
    }
}
