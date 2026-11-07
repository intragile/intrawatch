<?php

namespace App\Console\Commands;

use Amp\DeferredCancellation;
use App\Monitoring\Checks\CheckerRegistry;
use App\Monitoring\Domain\DomainExpiryService;
use App\Monitoring\Engine\DeadlineEvaluator;
use App\Monitoring\Engine\ResultRecorder;
use App\Monitoring\Worker\Clock;
use App\Monitoring\Worker\Heartbeat;
use App\Monitoring\Worker\MonitorLoader;
use App\Monitoring\Worker\OutcomeBuffer;
use App\Monitoring\Worker\Scheduler;
use App\Monitoring\Worker\WorkerDatabase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Revolt\EventLoop;
use Revolt\EventLoop\UnsupportedFeatureException;

#[Signature('intrawatch:worker {--shard=0} {--shards=1}')]
#[Description('Run the check worker for one shard of the monitors until SIGTERM or SIGINT')]
class WorkerCommand extends Command
{
    private const SIGINT = 2;

    private const SIGTERM = 15;

    public function handle(ResultRecorder $recorder, CheckerRegistry $checkers, DeadlineEvaluator $deadlines, Clock $clock, DomainExpiryService $domains): int
    {
        $shard = $this->integerOption('shard');
        $shards = $this->integerOption('shards');
        if ($shards === null || $shards < 1 || $shard === null || $shard < 0 || $shard >= $shards) {
            $this->error('--shards must be at least 1 and --shard between 0 and shards - 1.');

            return self::INVALID;
        }

        // Every blocking database wait stalls the event loop; give up on locks quickly, the buffer retries.
        // Applied before anything else so no part of the worker runs on a connection without these limits.
        WorkerDatabase::apply();

        // Broadcast events go through the queue. The sync driver would run them inline, with blocking HTTP
        // calls to the broadcaster inside the event loop.
        if (config()->string('queue.default') === 'sync') {
            $this->error('The check worker needs a real queue connection: QUEUE_CONNECTION is sync.');

            return self::FAILURE;
        }

        $scheduler = new Scheduler(
            shard: $shard,
            shards: $shards,
            loader: new MonitorLoader($clock, $domains),
            buffer: new OutcomeBuffer($recorder, $clock, afterFlush: $domains->flushPending(...)),
            heartbeat: new Heartbeat($clock, $shard, $shards),
            checkers: $checkers,
            deadlines: $deadlines,
            clock: $clock,
            concurrency: config()->integer('monitoring.worker.concurrency'),
        );

        $stop = new DeferredCancellation;
        $signals = [];
        foreach ([self::SIGTERM => 'SIGTERM', self::SIGINT => 'SIGINT'] as $number => $name) {
            try {
                $signals[] = EventLoop::unreference(EventLoop::onSignal($number, function () use ($stop, $name): void {
                    if (! $stop->isCancelled()) {
                        $this->line("Received {$name}, finishing checks in flight.");
                        $stop->cancel();
                    }
                }));
            } catch (UnsupportedFeatureException) {
                $this->warn("{$name} cannot be handled without the pcntl extension; the worker stops only when killed.");
            }
        }

        $this->line("Check worker started for shard {$shard} of {$shards}.");
        $scheduler->run($stop->getCancellation());

        foreach ($signals as $id) {
            EventLoop::cancel($id);
        }
        $this->line('Check worker stopped.');

        return self::SUCCESS;
    }

    private function integerOption(string $name): ?int
    {
        $value = $this->option($name);

        return is_string($value) && preg_match('/^\d+$/', $value) === 1 ? (int) $value : null;
    }
}
