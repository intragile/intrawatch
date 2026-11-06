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
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Revolt\EventLoop;
use Revolt\EventLoop\UnsupportedFeatureException;
use Throwable;

use function Amp\async;
use function Amp\delay;
use function Amp\Socket\listen;

#[Signature('intrawatch:bench {--monitors=1000} {--interval=60} {--duration=180} {--force : Run even though the database holds other workspaces}')]
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

    /**
     * A run that records fewer than this share of the expected checks fell behind its schedule.
     */
    private const MIN_CHECK_RATIO = 0.95;

    private const QUEUE = 'intrawatch-bench';

    private const SIGINT = 2;

    private const SIGTERM = 15;

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
        // A worker checking the same database would compete for the CPU and the database with the measurement.
        if (DB::table('check_workers')->where('last_seen_at', '>=', CarbonImmutable::now()->subMinute())->exists()) {
            $this->error('A check worker reported within the last minute. Run the benchmark in a separate container without workers.');

            return self::FAILURE;
        }
        if (! $this->option('force') && Workspace::query()->exists()) {
            $this->error('The database already holds workspaces. Run the benchmark against an empty database, or pass --force.');

            return self::FAILURE;
        }

        $stop = new DeferredCancellation;
        $interrupted = false;
        $signals = $this->onInterrupt(static function () use ($stop, &$interrupted): void {
            $interrupted = true;
            $stop->cancel();
        });
        $previousQueue = config()->string('queue.default');
        $server = listen('127.0.0.1:0');
        $workspace = null;

        try {
            $address = $server->getAddress();
            $port = $address instanceof InternetAddress ? $address->getPort() : 0;
            $acceptor = async(fn () => $this->serve($server));

            // Broadcast jobs go to a queue of their own, so the clean-up can remove exactly those.
            config([
                'queue.connections.'.self::QUEUE => [
                    'driver' => 'database',
                    'connection' => null,
                    'table' => config()->string('queue.connections.database.table', 'jobs'),
                    'queue' => self::QUEUE,
                    'retry_after' => 90,
                    'after_commit' => false,
                ],
                'queue.default' => self::QUEUE,
            ]);

            $workspace = $this->seed($monitors, $interval, "http://127.0.0.1:{$port}/");
            $this->line("Seeded {$monitors} http monitors at {$interval} s in workspace {$workspace->slug}; running for {$duration} s.");

            $buffer = new OutcomeBuffer($recorder, $clock, afterFlush: $domains->flushPending(...));
            $scheduler = new Scheduler(
                shard: 0,
                shards: 1,
                loader: new MonitorLoader($clock, $domains, $workspace->id),
                buffer: $buffer,
                // The benchmark owns no shard; its heartbeat must never replace a real worker's row.
                heartbeat: new Heartbeat($clock, 0, 1, persist: false),
                checkers: $checkers,
                deadlines: $deadlines,
                clock: $clock,
                concurrency: config()->integer('monitoring.worker.concurrency'),
            );

            // Seeded inactive, so that an interrupted seeding never leaves monitors a real worker would check.
            Monitor::query()->where('workspace_id', $workspace->id)->update(['active' => true]);

            $timer = EventLoop::delay($duration, static fn () => $stop->cancel());
            $startedAt = microtime(true);
            $scheduler->run($stop->getCancellation());
            $elapsed = microtime(true) - $startedAt;
            EventLoop::cancel($timer);

            $server->close();
            $acceptor->await();

            if ($interrupted) {
                $this->warn('Interrupted; no results are reported.');

                return self::FAILURE;
            }

            return $this->report($workspace, $monitors, $interval, $elapsed, $buffer->maxFlushMs());
        } finally {
            $server->close();
            foreach ($signals as $id) {
                EventLoop::cancel($id);
            }
            if ($workspace !== null) {
                $this->cleanUp($workspace);
            }
            DB::table(config()->string('queue.connections.database.table', 'jobs'))->where('queue', self::QUEUE)->delete();
            config(['queue.default' => $previousQueue]);
        }
    }

    /**
     * Ends the run on SIGINT or SIGTERM, so that the clean-up always runs.
     *
     * @return list<string> Callback ids to cancel afterwards.
     */
    private function onInterrupt(Closure $handler): array
    {
        $ids = [];
        foreach ([self::SIGINT, self::SIGTERM] as $signal) {
            try {
                $ids[] = EventLoop::unreference(EventLoop::onSignal($signal, $handler));
            } catch (UnsupportedFeatureException) {
                $this->warn('Signals cannot be handled without the pcntl extension; an interrupted run leaves its workspace behind.');

                break;
            }
        }

        return $ids;
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
                    'active' => false,
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

    private function report(Workspace $workspace, int $monitors, int $interval, float $elapsed, int $maxFlushMs): int
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
        // Each monitor's first check falls somewhere in its first interval, so a run of k intervals
        // checks every monitor at least k times.
        $expected = $monitors * (int) floor($elapsed / $interval);

        $this->table(['metric', 'value'], [
            ['checks', (string) count($drifts)],
            ['expected checks', ">= {$expected}"],
            ['checks not up', (string) $failed],
            ['checks per minute', number_format($perMinute, 1, '.', '')],
            ['drift p50 (ms)', (string) $this->percentile($drifts, 0.50)],
            ['drift p95 (ms)', (string) $this->percentile($drifts, 0.95)],
            ['drift max (ms)', (string) $max],
            ['max flush (ms)', (string) $maxFlushMs],
        ]);

        $failures = self::verdict(count($drifts), $expected, $failed, $max);
        foreach ($failures as $failure) {
            $this->error($failure);
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Why a run failed: too much drift, too few checks for its duration, or checks that were not up although
     * the target always answers.
     *
     * @return list<string>
     */
    public static function verdict(int $checks, int $expected, int $failed, int $maxDriftMs): array
    {
        $failures = [];
        if ($maxDriftMs >= self::MAX_DRIFT_MS) {
            $failures[] = "Maximum drift {$maxDriftMs} ms is not below ".self::MAX_DRIFT_MS.' ms.';
        }
        if ($checks < self::MIN_CHECK_RATIO * $expected) {
            $failures[] = "Only {$checks} of at least {$expected} expected checks ran.";
        }
        if ($failed > 0) {
            $failures[] = "{$failed} checks were not up.";
        }

        return $failures;
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
        });
        $this->line("Deleted workspace {$workspace->slug}.");
    }

    private function positiveOption(string $name): ?int
    {
        $value = $this->option($name);

        return is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1 ? (int) $value : null;
    }
}
