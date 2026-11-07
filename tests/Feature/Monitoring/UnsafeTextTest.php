<?php

namespace Tests\Feature\Monitoring;

use Amp\Dns\DnsRecord;
use Amp\Dns\DnsResolver;
use Amp\NullCancellation;
use App\Enums\MonitorStatus;
use App\Models\CheckResult;
use App\Models\Monitor;
use App\Models\MonitorEvent;
use App\Monitoring\Checks\Dns\ResolverFactory;
use App\Monitoring\Checks\DnsChecker;
use App\Monitoring\Checks\Http\ClientFactory;
use App\Monitoring\Checks\HttpChecker;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Engine\ResultRecorder;
use App\Monitoring\Net\GuardedConnector;
use App\Monitoring\Net\NetworkPolicy;
use App\Monitoring\Worker\OutcomeBuffer;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDOException;
use Tests\Support\FakeClock;
use Tests\Support\HttpFixtureServer;
use Tests\Support\RecordDnsResolver;
use Tests\Support\StubDnsResolver;
use Tests\TestCase;

/**
 * Text from checked targets (response bodies, DNS records, push messages) can hold NUL bytes and invalid UTF-8,
 * which Postgres and MySQL reject. It is cleaned before it is stored, and an outcome the database still
 * rejects for its data is dropped on its own instead of blocking every outcome queued behind it.
 */
class UnsafeTextTest extends TestCase
{
    use RefreshDatabase;

    private ?HttpFixtureServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();

        parent::tearDown();
    }

    public function test_a_json_query_message_with_a_nul_byte_from_the_body_is_recorded(): void
    {
        $this->server = new HttpFixtureServer;
        $monitor = Monitor::factory()->http($this->server->url('/'))->create(['retries' => 0]);
        $config = [
            'url' => $this->server->url('/json?content='.rawurlencode('{"state":"bad\u0000x"}')),
            'method' => 'GET', 'headers' => [], 'body' => null, 'accepted_status' => ['200-299'], 'max_redirects' => 5,
            'keyword' => null, 'json_query' => ['path' => '$.state', 'operator' => '==', 'expected' => 'good'],
            'header_checks' => [], 'auth' => ['type' => 'none', 'username' => null], 'mtls' => false, 'proxy' => null,
            'tls_verify' => true, 'tls_checks' => false, 'tls_expiry_days' => [30, 14, 7, 1], 'domain_expiry' => false,
        ];
        $spec = MonitorSpec::fromModel($monitor);
        $spec = new MonitorSpec($spec->id, $spec->workspaceId, $spec->type, $config, [], 60, 5, 0, 60, null, false, null, 0, null);
        $checker = new HttpChecker(new ClientFactory(new GuardedConnector(new StubDnsResolver([]), new NetworkPolicy([]))));

        $outcome = $checker->check($spec, new NullCancellation);
        $this->app->make(ResultRecorder::class)->record([[$spec, $outcome, CarbonImmutable::now(), null]]);

        $result = CheckResult::where('monitor_id', $monitor->id)->sole();
        $this->assertSame(MonitorStatus::Down, $result->status);
        $this->assertSame('JSON query $.state == good failed (got badx)', $result->message);
    }

    public function test_dns_txt_values_with_nul_bytes_and_invalid_utf8_are_recorded(): void
    {
        $resolver = new RecordDnsResolver;
        $factory = new class($resolver) extends ResolverFactory
        {
            public function __construct(private readonly RecordDnsResolver $stub) {}

            public function make(?string $address): DnsResolver
            {
                return $this->stub;
            }
        };
        $monitor = Monitor::factory()->dns('example.test', 'TXT')->create(['retries' => 0]);
        $spec = MonitorSpec::fromModel($monitor);
        $spec = new MonitorSpec($spec->id, $spec->workspaceId, $spec->type, [
            'name' => 'example.test', 'record_type' => 'TXT', 'resolver' => null,
            'expected' => ['v=ok'], 'match' => 'any', 'watch_changes' => true,
        ], [], 60, 5, 0, 60, null, false, null, 0, null);
        $checker = new DnsChecker($factory, new NetworkPolicy);
        $recorder = $this->app->make(ResultRecorder::class);

        $resolver->set('example.test', DnsRecord::TXT, ["one\0\xFF"]);
        $recorder->record([[$spec, $checker->check($spec, new NullCancellation), CarbonImmutable::now(), null]]);
        $resolver->set('example.test', DnsRecord::TXT, ["two\0\xC3"]);
        $recorder->record([[$spec, $checker->check($spec, new NullCancellation), CarbonImmutable::now()->addMinute(), null]]);

        $results = CheckResult::where('monitor_id', $monitor->id)->orderBy('id')->get();
        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertSame(MonitorStatus::Down, $result->status);
            $this->assertClean((string) $result->message);
            $this->assertIsArray($result->facts);
            $this->assertClean((string) json_encode($result->facts, JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame('Expected v=ok got two?', $results->last()?->message);

        $event = MonitorEvent::where('monitor_id', $monitor->id)->where('kind', MonitorEvent::KIND_CHANGED)->sole();
        $this->assertSame('one?', $event->old_value);
        $this->assertSame('two?', $event->new_value);
    }

    public function test_a_batch_the_database_rejects_for_its_data_is_recorded_item_by_item(): void
    {
        $clock = new FakeClock(CarbonImmutable::parse('2026-11-06 10:00:00', 'UTC'));
        $monitors = Monitor::factory()->count(3)->http()->create();
        DB::connection()->beforeExecuting(function (string $query, array $bindings): void {
            if (str_starts_with($query, 'insert into') && str_contains($query, 'check_results') && in_array('poison', $bindings, true)) {
                $rejected = new PDOException('SQLSTATE[22021]: Character not in repertoire: 7 ERROR:  invalid byte sequence for encoding "UTF8"');
                $rejected->errorInfo = ['22021', 7, 'invalid byte sequence for encoding "UTF8"'];
                throw new QueryException('pgsql', $query, [], $rejected);
            }
        });
        $errors = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$errors): void {
            if ($e->level === 'error') {
                $errors[] = $e->message;
            }
        });

        $buffer = new OutcomeBuffer($this->app->make(ResultRecorder::class), $clock, reconnect: fn () => null);
        foreach ($monitors as $i => $monitor) {
            $outcome = $i === 1 ? CheckOutcome::down('poison') : CheckOutcome::up(10);
            $buffer->push(MonitorSpec::fromModel($monitor), $outcome, $clock->now(), null, fn () => null);
        }

        $this->assertTrue($buffer->flush(), 'the other outcomes are recorded in the same flush');
        $this->assertSame(0, $buffer->count());
        $this->assertSame([1, 0, 1], $monitors->map(fn (Monitor $m): int => CheckResult::where('monitor_id', $m->id)->count())->all());
        $this->assertNull($buffer->outages()->failingSince(), 'a rejected outcome is no database outage');
        $this->assertSame(['Dropped a check outcome the database rejects.'], $errors);
    }

    public function test_database_errors_are_logged_by_sqlstate_without_the_driver_message(): void
    {
        $clock = new FakeClock(CarbonImmutable::parse('2026-11-06 10:00:00', 'UTC'));
        $monitor = Monitor::factory()->http()->create();
        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_starts_with($query, 'insert into') && str_contains($query, 'check_results')) {
                throw new QueryException('mysql', $query, [], new PDOException("SQLSTATE[HY000]: General error: 1366 Incorrect string value: '\\xF0secret' for column 'message'"));
            }
        });
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e->context;
        });

        $buffer = new OutcomeBuffer($this->app->make(ResultRecorder::class), $clock, reconnect: fn () => null);
        $buffer->push(MonitorSpec::fromModel($monitor), CheckOutcome::up(10), $clock->now(), null, fn () => null);
        $buffer->flush();

        $this->assertNotSame([], $logged);
        foreach ($logged as $context) {
            $this->assertStringNotContainsString('secret', (string) json_encode($context));
        }
        $this->assertContains(['sqlstate' => 'HY000', 'driver_code' => 1366], array_map(
            fn (array $context): array => array_intersect_key($context, ['sqlstate' => true, 'driver_code' => true]),
            $logged,
        ));
    }

    private function assertClean(string $text): void
    {
        $this->assertStringNotContainsString("\0", $text);
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'), 'valid UTF-8: '.bin2hex($text));
    }
}
