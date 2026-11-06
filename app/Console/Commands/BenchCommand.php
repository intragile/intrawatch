<?php

namespace App\Console\Commands;

use Amp\DeferredCancellation;
use Amp\Socket\InternetAddress;
use Amp\Socket\ServerSocket;
use Amp\Socket\Socket;
use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\Workspace;
use App\Monitoring\Checks\CheckerRegistry;
use App\Monitoring\Domain\DomainExpiryService;
use App\Monitoring\Engine\DeadlineEvaluator;
use App\Monitoring\Engine\ResultRecorder;
use App\Monitoring\Worker\Clock;
use App\Monitoring\Worker\Heartbeat;
use App\Monitoring\Worker\MonitorLoader;
use App\Monitoring\Worker\OutcomeBuffer;
use App\Monitoring\Worker\Scheduler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Revolt\EventLoop;
use Throwable;

use function Amp\async;
use function Amp\delay;
use function Amp\Socket\listen;

#[Signature('intrawatch:bench {--monitors=1000} {--interval=60} {--duration=180}')]
#[Description('Measure schedule drift of one check worker against a local HTTP target with 50-300 ms latency')]
class BenchCommand extends Command
{
    /**
     * The worst acceptable delay between a check's due time and its start.
     */
    private const MAX_DRIFT_MS = 2000;

    private const MIN_LATENCY_MS = 50;

    private const MAX_LATENCY_MS = 300;

    private const MAX_REQUEST_HEAD_BYTES = 65536;

    public function handle(ResultRecorder $recorder, CheckerRegistry $checkers, DeadlineEvaluator $deadlines, Clock $clock, DomainExpiryService $domains): int
    {
        $monitors = $this->positiveOption('monitors');
        $interval = $this->positiveOption('interval');
        $duration = $this->positiveOption('duration');
        if ($monitors === null || $interval === null || $duration === null) {
            $this->error('--monitors, --interval and --duration must be positive whole numbers.');

            return self::INVALID;
        }
        if ($interval < config()->integer('monitoring.limits.interval.min')) {
            $this->error('--interval must be at least '.config()->integer('monitoring.limits.interval.min').' seconds.');

            return self::INVALID;
        }
        if (config()->string('queue.default') === 'sync') {
            $this->error('The benchmark needs a real queue connection: QUEUE_CONNECTION is sync.');

            return self::FAILURE;
        }
        // The benchmark writes the heartbeat of shard 0; it must not stand in for, or race with, a real worker.
        if (DB::table('check_workers')->where('last_seen_at', '>=', CarbonImmutable::now()->subMinute())->exists()) {
            $this->error('A check worker reported within the last minute. Run the benchmark in a separate container without workers.');

            return self::FAILURE;
        }

        $server = listen('127.0.0.1:0');
        $address = $server->getAddress();
        $port = $address instanceof InternetAddress ? $address->getPort() : 0;
        $acceptor = async(fn () => $this->serve($server));

        $workspace = $this->seed($monitors, $interval, "http://127.0.0.1:{$port}/");
        $this->line("Seeded {$monitors} http monitors at {$interval} s in workspace {$workspace->slug}; running for {$duration} s.");

        try {
            $buffer = new OutcomeBuffer($recorder, $clock, afterFlush: $domains->flushPending(...));
            $scheduler = new Scheduler(
                shard: 0,
                shards: 1,
                loader: new MonitorLoader($clock, $domains, $workspace->id),
                buffer: $buffer,
                heartbeat: new Heartbeat($clock, 0, 1),
                checkers: $checkers,
                deadlines: $deadlines,
                clock: $clock,
                concurrency: config()->integer('monitoring.worker.concurrency'),
            );

            $stop = new DeferredCancellation;
            $timer = EventLoop::delay($duration, static fn () => $stop->cancel());
            $startedAt = microtime(true);
            $scheduler->run($stop->getCancellation());
            $elapsed = microtime(true) - $startedAt;
            EventLoop::cancel($timer);

            $server->close();
            $acceptor->await();

            return $this->report($workspace, $elapsed, $buffer->maxFlushMs());
        } finally {
            $server->close();
            $this->cleanUp($workspace);
        }
    }

    private function seed(int $count, int $interval, string $url): Workspace
    {
        $name = 'Benchmark '.Str::lower(Str::random(8));
        $timeout = min(config()->integer('monitoring.limits.timeout.default'), (int) floor($interval * 0.8));
        $config = MonitorType::Http->definition()->defaults();
        $config['url'] = $url;

        return DB::transaction(function () use ($name, $count, $interval, $timeout, $config): Workspace {
            $workspace = Workspace::query()->create(['name' => $name, 'slug' => Workspace::uniqueSlug($name), 'timezone' => 'UTC']);
            for ($i = 1; $i <= $count; $i++) {
                (new Monitor([
                    'name' => "bench {$i}",
                    'type' => MonitorType::Http,
                    'active' => true,
                    'interval_seconds' => $interval,
                    'timeout_seconds' => max(1, $timeout),
                    'retries' => 0,
                    'retry_interval_seconds' => $interval,
                    'config' => $config,
                ] + ['workspace_id' => $workspace->id]))->save();
            }

            return $workspace;
        });
    }

    private function report(Workspace $workspace, float $elapsed, int $maxFlushMs): int
    {
        $drifts = array_map(
            static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0,
            DB::table('check_results')
                ->join('monitors', 'monitors.id', '=', 'check_results.monitor_id')
                ->where('monitors.workspace_id', $workspace->id)
                ->whereNotNull('check_results.drift_ms')
                ->pluck('check_results.drift_ms')
                ->all(),
        );
        sort($drifts);
        $failed = DB::table('check_results')
            ->join('monitors', 'monitors.id', '=', 'check_results.monitor_id')
            ->where('monitors.workspace_id', $workspace->id)
            ->where('check_results.status', '!=', 'up')
            ->count();

        if ($drifts === []) {
            $this->error('No check results were recorded.');

            return self::FAILURE;
        }

        $max = $drifts[count($drifts) - 1];
        $perMinute = count($drifts) / max(1.0, $elapsed) * 60;

        $this->table(['metric', 'value'], [
            ['checks', (string) count($drifts)],
            ['checks not up', (string) $failed],
            ['checks per minute', number_format($perMinute, 1, '.', '')],
            ['drift p50 (ms)', (string) $this->percentile($drifts, 0.50)],
            ['drift p95 (ms)', (string) $this->percentile($drifts, 0.95)],
            ['drift max (ms)', (string) $max],
            ['max flush (ms)', (string) $maxFlushMs],
        ]);

        if ($max >= self::MAX_DRIFT_MS) {
            $this->error("Maximum drift {$max} ms is not below ".self::MAX_DRIFT_MS.' ms.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $sorted
     */
    private function percentile(array $sorted, float $p): int
    {
        return $sorted[max(0, (int) ceil($p * count($sorted)) - 1)];
    }

    /**
     * Accepts connections until the server is closed.
     */
    private function serve(ServerSocket $server): void
    {
        while ($client = $server->accept()) {
            async(fn () => $this->respond($client));
        }
    }

    /**
     * A minimal HTTP/1.1 responder: answers every request on a keep-alive connection with a short body after
     * a random delay. Request bodies are not expected; the checks send GET requests.
     */
    private function respond(Socket $client): void
    {
        $buffer = '';
        try {
            while (true) {
                while (($end = strpos($buffer, "\r\n\r\n")) === false) {
                    $chunk = $client->read();
                    if ($chunk === null || strlen($buffer) > self::MAX_REQUEST_HEAD_BYTES) {
                        return;
                    }
                    $buffer .= $chunk;
                }
                $buffer = substr($buffer, $end + 4);
                delay(random_int(self::MIN_LATENCY_MS, self::MAX_LATENCY_MS) / 1000);
                $client->write("HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 2\r\n\r\nok");
            }
        } catch (Throwable) {
            // The client went away; nothing to clean up beyond the socket.
        } finally {
            $client->close();
        }
    }

    private function cleanUp(Workspace $workspace): void
    {
        DB::transaction(function () use ($workspace): void {
            $ids = DB::table('monitors')->where('workspace_id', $workspace->id)->pluck('id')->all();
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table('check_results')->whereIn('monitor_id', $chunk)->delete();
                DB::table('monitor_events')->whereIn('monitor_id', $chunk)->delete();
            }
            $workspace->delete();
            DB::table('check_workers')->where('shard', 0)->where('shards', 1)->delete();
        });
        $this->line("Deleted workspace {$workspace->slug}.");
    }

    private function positiveOption(string $name): ?int
    {
        $value = $this->option($name);

        return is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1 ? (int) $value : null;
    }
}
