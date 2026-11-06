<?php

namespace Tests\Feature\Monitoring\Checks;

use Amp\NullCancellation;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\MonitorEvent;
use App\Monitoring\Checks\CheckerRegistry;
use App\Monitoring\Checks\TlsChecker;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Engine\ResultRecorder;
use App\Monitoring\Net\GuardedConnector;
use App\Monitoring\Net\NetworkPolicy;
use App\Monitoring\Net\TlsInspector;
use App\Monitoring\Worker\SystemClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StubDnsResolver;
use Tests\Support\TlsFixtures;
use Tests\Support\TlsFixtureServer;
use Tests\TestCase;

class TlsCheckerTest extends TestCase
{
    use RefreshDatabase;

    private const HOSTS = [
        'secure.test' => ['127.0.0.1'],
        'other.test' => ['127.0.0.1'],
        'a.wild.test' => ['127.0.0.1'],
        'a.b.wild.test' => ['127.0.0.1'],
    ];

    /** @var list<TlsFixtureServer> */
    private array $servers = [];

    public static function tearDownAfterClass(): void
    {
        TlsFixtures::release();

        parent::tearDownAfterClass();
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->servers = [];

        parent::tearDown();
    }

    private function fixtures(): TlsFixtures
    {
        return TlsFixtures::shared();
    }

    /**
     * @param  array<string, mixed>  $sslOptions
     */
    private function serve(string $certificate, string $mode = 'none', array $sslOptions = []): TlsFixtureServer
    {
        return $this->servers[] = new TlsFixtureServer($certificate, $mode, $sslOptions);
    }

    /**
     * @param  list<string>  $blocked
     */
    private function checker(array $blocked = []): TlsChecker
    {
        $connector = new GuardedConnector(new StubDnsResolver(self::HOSTS), new NetworkPolicy($blocked));

        return new TlsChecker(new TlsInspector($connector, new SystemClock, $this->fixtures()->caFile));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function spec(array $config, int $timeout = 5): MonitorSpec
    {
        return new MonitorSpec(
            id: 1,
            workspaceId: 1,
            type: MonitorType::Tls,
            config: $config + [
                'host' => 'secure.test',
                'starttls' => 'none',
                'servername' => null,
                'expiry_days' => [30, 14, 7, 1],
                'degraded_below_days' => 7,
                'check_protocols' => true,
                'check_ciphers' => true,
                'watch_certificate' => true,
            ],
            secrets: [],
            intervalSeconds: 3600,
            timeoutSeconds: $timeout,
            retries: 1,
            retryIntervalSeconds: 3600,
            degradedThresholdMs: null,
            inverted: false,
            parentId: null,
            updatedAt: 0,
            pushToken: null,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function probe(array $config, int $timeout = 5, ?TlsChecker $checker = null): CheckOutcome
    {
        return ($checker ?? $this->checker())->check($this->spec($config, $timeout), new NullCancellation);
    }

    public function test_valid_certificate_up_with_facts(): void
    {
        $server = $this->serve($this->fixtures()->valid);

        $outcome = $this->probe(['port' => $server->port()]);

        $this->assertSame(MonitorStatus::Up, $outcome->status, $outcome->message);
        $this->assertContains($outcome->facts['tls.days_left'], [89, 90]);
        $this->assertIsString($outcome->facts['tls.not_after']);
        $this->assertEqualsWithDelta(time() + 90 * 86400, strtotime($outcome->facts['tls.not_after']), 120);
        $this->assertSame('CN=IntraWatch Test Root', $outcome->facts['tls.issuer']);
        $this->assertSame('TLSv1.3', $outcome->facts['tls.protocol']);
        $this->assertIsString($outcome->facts['tls.cipher']);
        $this->assertNotSame('', $outcome->facts['tls.cipher']);
        $this->assertSame([], $outcome->facts['tls.deprecated_protocols']);
        $this->assertNotNull($outcome->latencyMs);
        $this->assertSame([
            'tls.expiry_band' => 'ok',
            'tls.fingerprint' => openssl_x509_fingerprint($this->fixtures()->validCertificate, 'sha256'),
            'tls.issuer' => 'CN=IntraWatch Test Root',
        ], $outcome->watch);
    }

    public function test_expired_wrong_host_self_signed_incomplete_chain_are_down(): void
    {
        $fixtures = $this->fixtures();
        $cases = [
            'Certificate expired' => $fixtures->expired,
            'Certificate hostname_mismatch' => $fixtures->wrongHost,
            'Certificate self_signed' => $fixtures->selfSigned,
            'Certificate incomplete_chain' => $fixtures->missingIntermediate,
        ];

        foreach ($cases as $message => $certificate) {
            $server = $this->serve($certificate);
            $outcome = $this->probe(['port' => $server->port()]);

            $this->assertSame(MonitorStatus::Down, $outcome->status, $message);
            $this->assertSame($message, $outcome->message);
            // The certificate is still read, so facts and the band are reported for a failing check.
            $this->assertArrayHasKey('tls.fingerprint', $outcome->watch, $message);
            $this->assertIsInt($outcome->facts['tls.days_left'], $message);
        }

        $expired = $this->probe(['port' => $this->serve($fixtures->expired)->port()]);
        $this->assertSame(-1, $expired->facts['tls.days_left']);
        $this->assertSame('expired', $expired->watch['tls.expiry_band']);
    }

    public function test_full_chain_with_intermediate_is_valid(): void
    {
        $outcome = $this->probe(['port' => $this->serve($this->fixtures()->withIntermediate)->port()]);

        $this->assertSame(MonitorStatus::Up, $outcome->status, $outcome->message);
        $this->assertSame('CN=IntraWatch Test Intermediate', $outcome->facts['tls.issuer']);
    }

    public function test_wildcard_covers_one_label_and_servername_overrides_the_peer_name(): void
    {
        $port = $this->serve($this->fixtures()->wildcard)->port();

        $this->assertSame(MonitorStatus::Up, $this->probe(['host' => 'a.wild.test', 'port' => $port])->status);
        $this->assertSame('Certificate hostname_mismatch', $this->probe(['host' => 'a.b.wild.test', 'port' => $port])->message);

        // The connection goes to the host, the name checked is the servername.
        $this->assertSame(MonitorStatus::Up, $this->probe(['host' => '127.0.0.1', 'servername' => 'a.wild.test', 'port' => $port])->status);
        $this->assertSame('Certificate hostname_mismatch', $this->probe(['host' => '127.0.0.1', 'port' => $port])->message);
    }

    public function test_expiring_certificate_degrades(): void
    {
        $port = $this->serve($this->fixtures()->expiring)->port();

        $outcome = $this->probe(['port' => $port]);
        $this->assertSame(MonitorStatus::Degraded, $outcome->status);
        $this->assertContains($outcome->message, ['Certificate expires in 2 days', 'Certificate expires in 3 days']);
        $this->assertSame('7', $outcome->watch['tls.expiry_band']);

        $relaxed = $this->probe(['port' => $port, 'degraded_below_days' => 1]);
        $this->assertSame(MonitorStatus::Up, $relaxed->status, $relaxed->message);
    }

    public function test_certificate_change_is_watched(): void
    {
        $server = $this->serve($this->fixtures()->valid);
        $monitor = Monitor::factory()->tls('secure.test')->create();
        $recorder = $this->app->make(ResultRecorder::class);
        $record = function () use ($monitor, $recorder, $server): void {
            $spec = MonitorSpec::fromModel($monitor->fresh() ?? $monitor);
            $recorder->record([[$spec, $this->probe(['port' => $server->port()]), CarbonImmutable::now(), 5]]);
        };

        $record();
        $record();
        $this->assertSame(0, MonitorEvent::where('kind', MonitorEvent::KIND_CHANGED)->count());

        $server->useCertificate($this->fixtures()->renewed);
        $record();
        $events = MonitorEvent::where('kind', MonitorEvent::KIND_CHANGED)->get();
        $this->assertCount(1, $events);
        $this->assertSame('tls.fingerprint', $events->firstOrFail()->key);

        $unwatched = $this->probe(['port' => $server->port(), 'watch_certificate' => false]);
        $this->assertSame(['tls.expiry_band' => 'ok'], $unwatched->watch);
    }

    public function test_starttls_smtp(): void
    {
        $outcome = $this->probe(['port' => $this->serve($this->fixtures()->valid, 'smtp')->port(), 'starttls' => 'smtp']);

        $this->assertSame(MonitorStatus::Up, $outcome->status, $outcome->message);
        $this->assertSame([], $outcome->facts['tls.deprecated_protocols']);
    }

    public function test_starttls_imap_and_pop3(): void
    {
        foreach (['imap', 'pop3'] as $protocol) {
            $outcome = $this->probe(['port' => $this->serve($this->fixtures()->valid, $protocol)->port(), 'starttls' => $protocol]);

            $this->assertSame(MonitorStatus::Up, $outcome->status, $protocol.': '.$outcome->message);
        }
    }

    public function test_starttls_not_offered_or_failing_is_down(): void
    {
        $missing = $this->probe(['port' => $this->serve($this->fixtures()->valid, 'smtp-without-starttls')->port(), 'starttls' => 'smtp']);
        $this->assertSame(MonitorStatus::Down, $missing->status);
        $this->assertSame('STARTTLS not offered', $missing->message);

        $flood = $this->probe(['port' => $this->serve($this->fixtures()->valid, 'flood')->port(), 'starttls' => 'smtp']);
        $this->assertSame('STARTTLS failed', $flood->message);

        // A server that speaks TLS right away is not an SMTP server.
        $plain = $this->probe(['port' => $this->serve($this->fixtures()->valid)->port(), 'starttls' => 'imap'], timeout: 2);
        $this->assertSame(MonitorStatus::Down, $plain->status);
        $this->assertContains($plain->message, ['STARTTLS failed', 'Timed out after 2 s']);
    }

    public function test_server_that_never_answers_times_out(): void
    {
        $port = $this->serve($this->fixtures()->valid, 'hang')->port();
        $started = microtime(true);

        $outcome = $this->probe(['port' => $port], timeout: 1);

        $this->assertSame('Timed out after 1 s', $outcome->message);
        $this->assertLessThan(3.0, microtime(true) - $started);

        $smtp = $this->probe(['port' => $port, 'starttls' => 'smtp'], timeout: 1);
        $this->assertSame('Timed out after 1 s', $smtp->message);
    }

    public function test_refused_and_blocked_targets(): void
    {
        $server = $this->serve($this->fixtures()->valid);
        $port = $server->port();
        $server->stop();

        $this->assertSame('Connection refused', $this->probe(['port' => $port])->message);
        $this->assertSame('Blocked target', $this->probe(['port' => $port], checker: $this->checker(['127.0.0.0/8']))->message);
    }

    public function test_weak_cipher_degrades_only_when_ciphers_are_checked(): void
    {
        $port = $this->serve($this->fixtures()->valid, sslOptions: [
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER,
            'ciphers' => 'ECDHE-ECDSA-AES128-SHA',
        ])->port();

        $outcome = $this->probe(['port' => $port]);
        $this->assertSame(MonitorStatus::Degraded, $outcome->status, $outcome->message);
        $this->assertSame('Weak cipher negotiated: ECDHE-ECDSA-AES128-SHA', $outcome->message);
        $this->assertSame('TLSv1.2', $outcome->facts['tls.protocol']);

        $this->assertSame(MonitorStatus::Up, $this->probe(['port' => $port, 'check_ciphers' => false])->status);
    }

    public function test_deprecated_protocols_are_probed(): void
    {
        $port = $this->serve($this->fixtures()->valid, sslOptions: [
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_0_SERVER | STREAM_CRYPTO_METHOD_TLSv1_1_SERVER | STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER,
            'ciphers' => 'DEFAULT:@SECLEVEL=0',
            'security_level' => 0,
        ])->port();

        $outcome = $this->probe(['port' => $port]);
        $deprecated = $outcome->facts['tls.deprecated_protocols'];
        if ($deprecated === []) {
            $this->markTestSkipped('The local OpenSSL refuses TLS 1.0 and 1.1 on both ends.');
        }

        $this->assertSame(['TLSv1.0', 'TLSv1.1'], $deprecated);
        $this->assertSame(MonitorStatus::Degraded, $outcome->status);
        $this->assertSame('Deprecated protocols accepted: TLSv1.0, TLSv1.1', $outcome->message);

        $unchecked = $this->probe(['port' => $port, 'check_protocols' => false]);
        $this->assertSame(MonitorStatus::Up, $unchecked->status);
        $this->assertSame([], $unchecked->facts['tls.deprecated_protocols']);
    }

    public function test_tls_type_is_registered(): void
    {
        $this->assertInstanceOf(TlsChecker::class, $this->app->make(CheckerRegistry::class)->for(MonitorType::Tls));
    }
}
