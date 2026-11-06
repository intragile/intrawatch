<?php

namespace Tests\Feature\Monitoring\Checks;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Dns\DnsRecord;
use Amp\Dns\DnsResolver;
use Amp\NullCancellation;
use Amp\TimeoutCancellation;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\MonitorEvent;
use App\Monitoring\Checks\Dns\RecordFormatter;
use App\Monitoring\Checks\Dns\ResolverFactory;
use App\Monitoring\Checks\DnsChecker;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Engine\ResultRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Revolt\EventLoop;
use Tests\Support\DnsFixtureServer;
use Tests\Support\MonitorSpecFactory;
use Tests\Support\RecordDnsResolver;
use Tests\TestCase;

class DnsCheckerTest extends TestCase
{
    use RefreshDatabase;

    private RecordDnsResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new RecordDnsResolver;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function spec(array $config = [], int $timeout = 5): MonitorSpec
    {
        return MonitorSpecFactory::of(MonitorType::Dns, $config + [
            'name' => 'example.test', 'record_type' => 'A', 'resolver' => null,
            'expected' => null, 'match' => 'any', 'watch_changes' => false,
        ], timeoutSeconds: $timeout);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function probe(array $config = [], int $timeout = 5): CheckOutcome
    {
        $factory = new class($this->resolver) extends ResolverFactory
        {
            public function __construct(private readonly RecordDnsResolver $stub) {}

            public function make(?string $address): DnsResolver
            {
                return $this->stub;
            }
        };

        return (new DnsChecker($factory))->check($this->spec($config, $timeout), new NullCancellation);
    }

    public function test_match_modes(): void
    {
        $this->resolver->set('example.test', DnsRecord::A, ['2.2.2.2', '1.1.1.1']);

        $this->assertSame(MonitorStatus::Up, $this->probe(['expected' => ['2.2.2.2'], 'match' => 'any'])->status);
        $this->assertSame(MonitorStatus::Up, $this->probe(['expected' => ['9.9.9.9', '1.1.1.1'], 'match' => 'any'])->status);
        $this->assertSame(MonitorStatus::Down, $this->probe(['expected' => ['9.9.9.9'], 'match' => 'any'])->status);

        $this->assertSame(MonitorStatus::Down, $this->probe(['expected' => ['1.1.1.1', '3.3.3.3'], 'match' => 'all'])->status);
        $this->assertSame(MonitorStatus::Up, $this->probe(['expected' => ['1.1.1.1'], 'match' => 'all'])->status);

        $this->assertSame(MonitorStatus::Up, $this->probe(['expected' => ['1.1.1.1', '2.2.2.2'], 'match' => 'exact'])->status);
        $this->assertSame(MonitorStatus::Down, $this->probe(['expected' => ['1.1.1.1'], 'match' => 'exact'])->status);

        $failed = $this->probe(['expected' => ['3.3.3.3'], 'match' => 'all']);
        $this->assertSame('Expected 3.3.3.3 got 1.1.1.1, 2.2.2.2', $failed->message);
    }

    public function test_no_expectation_passes_when_records_exist_and_exposes_sorted_fact(): void
    {
        $this->resolver->set('example.test', DnsRecord::A, ['2.2.2.2', '1.1.1.1']);

        $outcome = $this->probe();

        $this->assertSame(MonitorStatus::Up, $outcome->status);
        $this->assertSame(['1.1.1.1', '2.2.2.2'], $outcome->facts['dns.records']);
        $this->assertSame([], $outcome->watch);
    }

    public function test_mx_and_txt_formatting_and_case(): void
    {
        $this->assertSame('10 mail.example.test', RecordFormatter::format(new DnsRecord('10,Mail.Example.TEST.', DnsRecord::MX)));
        $this->assertSame('10 mail.example.test', RecordFormatter::format(new DnsRecord('10 Mail.Example.TEST.', DnsRecord::MX)));
        $this->assertSame('10 5 443 sip.example.test', RecordFormatter::format(new DnsRecord('10,5,443,SIP.Example.test.', DnsRecord::SRV)));
        $this->assertSame('v=spf1 -all', RecordFormatter::format(new DnsRecord('v=spf1 -all', DnsRecord::TXT)));
        $this->assertSame('ns1.example.test', RecordFormatter::format(new DnsRecord('NS1.Example.test.', DnsRecord::NS)));

        $this->resolver->set('example.test', DnsRecord::MX, ['10,Mail.Example.TEST.']);
        $outcome = $this->probe(['record_type' => 'MX', 'expected' => ['10 MAIL.example.test.'], 'match' => 'exact']);
        $this->assertSame(MonitorStatus::Up, $outcome->status, $outcome->message);
    }

    public function test_watch_order_independent(): void
    {
        $this->resolver->set('example.test', DnsRecord::A, ['2.2.2.2', '1.1.1.1']);
        $first = $this->probe(['watch_changes' => true]);
        $this->resolver->set('example.test', DnsRecord::A, ['1.1.1.1', '2.2.2.2']);
        $second = $this->probe(['watch_changes' => true]);

        $this->assertSame(['dns.records' => "1.1.1.1\n2.2.2.2"], $first->watch);
        $this->assertSame($first->watch, $second->watch);
    }

    public function test_change_produces_monitor_event_through_recorder(): void
    {
        $monitor = Monitor::factory()->dns()->create();
        $recorder = $this->app->make(ResultRecorder::class);
        $record = function () use ($monitor, $recorder): void {
            $spec = MonitorSpec::fromModel($monitor->fresh() ?? $monitor);
            $outcome = $this->probe(['watch_changes' => true]);
            $recorder->record([[$spec, $outcome, CarbonImmutable::now(), 5]]);
        };

        $this->resolver->set('example.test', DnsRecord::A, ['1.1.1.1']);
        $record();
        $record();
        $this->assertSame(0, MonitorEvent::where('kind', MonitorEvent::KIND_CHANGED)->count());

        $this->resolver->set('example.test', DnsRecord::A, ['1.1.1.1', '2.2.2.2']);
        $record();
        $events = MonitorEvent::where('kind', MonitorEvent::KIND_CHANGED)->get();
        $this->assertCount(1, $events);
        $this->assertSame('dns.records', $events->firstOrFail()->key);
    }

    public function test_nxdomain_missing_record_and_server_errors_are_down(): void
    {
        $nx = $this->probe(['name' => 'nx.example.test']);
        $this->assertSame(MonitorStatus::Down, $nx->status);
        $this->assertSame('No A record', $nx->message);

        $missing = $this->probe(['record_type' => 'TXT']);
        $this->assertSame('No TXT record', $missing->message);

        $broken = $this->probe(['name' => 'broken.example.test']);
        $this->assertSame(MonitorStatus::Down, $broken->status);
        $this->assertSame('DNS query failed', $broken->message);
    }

    public function test_long_mismatch_message_is_truncated(): void
    {
        $this->resolver->set('example.test', DnsRecord::TXT, [str_repeat('a', 400)]);

        $outcome = $this->probe(['record_type' => 'TXT', 'expected' => ['b'], 'match' => 'any']);

        $this->assertSame(300, mb_strlen($outcome->message));
    }

    public function test_query_that_never_answers_times_out(): void
    {
        $hang = new DeferredFuture;
        $resolver = new class($hang) implements DnsResolver
        {
            /** @param DeferredFuture<mixed> $hang */
            public function __construct(private readonly DeferredFuture $hang) {}

            public function resolve(string $name, ?int $typeRestriction = null, ?Cancellation $cancellation = null): array
            {
                return $this->query($name, $typeRestriction ?? 1, $cancellation);
            }

            public function query(string $name, int $type, ?Cancellation $cancellation = null): array
            {
                $this->hang->getFuture()->await($cancellation);

                return [new DnsRecord('1.1.1.1', DnsRecord::A)];
            }
        };
        $factory = new class($resolver) extends ResolverFactory
        {
            public function __construct(private readonly DnsResolver $stub) {}

            public function make(?string $address): DnsResolver
            {
                return $this->stub;
            }
        };

        // Timeout timers are unreferenced, so a referenced watcher keeps the loop alive in the test.
        $keepAlive = EventLoop::repeat(1, static fn () => null);
        $started = microtime(true);
        $outcome = (new DnsChecker($factory))->check($this->spec(timeout: 1), new NullCancellation);
        EventLoop::cancel($keepAlive);

        $this->assertSame('Timed out after 1 s', $outcome->message);
        $this->assertLessThan(3, microtime(true) - $started);
    }

    public function test_scheduler_cancellation_propagates(): void
    {
        $hang = new DeferredFuture;
        $resolver = new class($hang) implements DnsResolver
        {
            /** @param DeferredFuture<mixed> $hang */
            public function __construct(private readonly DeferredFuture $hang) {}

            public function resolve(string $name, ?int $typeRestriction = null, ?Cancellation $cancellation = null): array
            {
                return $this->query($name, 1, $cancellation);
            }

            public function query(string $name, int $type, ?Cancellation $cancellation = null): array
            {
                $this->hang->getFuture()->await($cancellation);

                return [new DnsRecord('1.1.1.1', DnsRecord::A)];
            }
        };
        $factory = new class($resolver) extends ResolverFactory
        {
            public function __construct(private readonly DnsResolver $stub) {}

            public function make(?string $address): DnsResolver
            {
                return $this->stub;
            }
        };

        $keepAlive = EventLoop::repeat(1, static fn () => null);
        $this->expectException(CancelledException::class);

        try {
            (new DnsChecker($factory))->check($this->spec(timeout: 30), new TimeoutCancellation(0.2));
        } finally {
            EventLoop::cancel($keepAlive);
        }
    }

    public function test_custom_resolver_address_is_used(): void
    {
        $server = new DnsFixtureServer('9.9.9.9');

        try {
            $outcome = (new DnsChecker(new ResolverFactory))->check(
                $this->spec(['resolver' => "127.0.0.1:{$server->port()}", 'expected' => ['9.9.9.9'], 'match' => 'exact']),
                new NullCancellation,
            );
            $this->assertSame(MonitorStatus::Up, $outcome->status, $outcome->message);
            $this->assertSame(['9.9.9.9'], $outcome->facts['dns.records']);
            $this->assertSame(1, $server->received);

            // Results are not cached between checks.
            (new DnsChecker(new ResolverFactory))->check($this->spec(['resolver' => "127.0.0.1:{$server->port()}"]), new NullCancellation);
            $this->assertSame(2, $server->received);
        } finally {
            $server->close();
        }
    }
}
