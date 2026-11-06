<?php

namespace App\Monitoring\Worker;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use App\Events\ChecksRecorded;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Engine\PostCommit;
use App\Monitoring\Engine\ResultRecorder;
use App\Monitoring\Engine\Transition;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use Throwable;

/**
 * Collects check outcomes and writes them through the ResultRecorder in batches.
 *
 * Outcomes stay queued until a record() call for them has returned, so a failed flush (database gone,
 * locked or restarting) loses nothing and a committed one is never written again. While the database is
 * unavailable the buffer keeps accepting outcomes up to its capacity and then drops the oldest.
 *
 * ChecksRecorded is broadcast from here rather than by the recorder, at most once per workspace per
 * broadcast interval, carrying the latest check of each monitor since the previous broadcast.
 */
final class OutcomeBuffer
{
    private const MAX_BACKOFF_SECONDS = 30;

    private const MESSAGE_LOG_LENGTH = 500;

    /**
     * Queued outcomes in arrival order, with the callback that learns the monitor's transition.
     *
     * @var array<int, array{item: array{0: MonitorSpec, 1: CheckOutcome, 2: CarbonImmutable, 3: ?int}, done: Closure(?Transition): void}>
     */
    private array $queue = [];

    private int $sequence = 0;

    private int $droppedSinceLog = 0;

    private DeferredCancellation $wake;

    /**
     * Latest recorded check per monitor, per workspace, waiting for the next broadcast.
     *
     * @var array<int, array<int, array{monitor_id: int, status: string, latency_ms: ?int, checked_at: string}>>
     */
    private array $unbroadcast = [];

    /** @var array<int, CarbonImmutable> */
    private array $lastBroadcast = [];

    /** @var Closure(): void */
    private readonly Closure $reconnect;

    /**
     * @param  int  $capacity  Most outcomes held; beyond that the oldest are dropped.
     * @param  int  $batchSize  Outcomes per record() call; a queue this long also triggers a flush early.
     * @param  ?Closure(): void  $reconnect  Re-establishes the database connection after a failed flush.
     */
    public function __construct(
        private readonly ResultRecorder $recorder,
        private readonly Clock $clock,
        private readonly int $capacity = 10_000,
        private readonly int $batchSize = 500,
        private readonly float $flushSeconds = 1.0,
        private readonly float $broadcastSeconds = 5.0,
        ?Closure $reconnect = null,
    ) {
        $this->reconnect = $reconnect ?? static function (): void {
            DB::reconnect();
        };
        $this->wake = new DeferredCancellation;
    }

    /**
     * @param  Closure(?Transition): void  $done  Called once the outcome is recorded, with the monitor's transition
     *                                            (null when the recorder skipped it), or with null when it is dropped.
     */
    public function push(MonitorSpec $spec, CheckOutcome $outcome, CarbonImmutable $checkedAt, ?int $driftMs, Closure $done): void
    {
        $this->queue[++$this->sequence] = ['item' => [$spec, $outcome, $checkedAt, $driftMs], 'done' => $done];

        while (count($this->queue) > $this->capacity && ($key = array_key_first($this->queue)) !== null) {
            $dropped = $this->queue[$key];
            unset($this->queue[$key]);
            $this->droppedSinceLog++;
            $this->notify($dropped['done'], null);
        }

        if (count($this->queue) >= $this->batchSize) {
            $this->wake->cancel();
        }
    }

    public function count(): int
    {
        return count($this->queue);
    }

    /**
     * Flushes every second (or as soon as a batch is full) until stopped. After a failed flush it waits
     * 1, 2, 4 ... up to 30 seconds before the next attempt.
     */
    public function run(Cancellation $stop): void
    {
        $failures = 0;

        while (true) {
            if ($this->wake->isCancelled()) {
                $this->wake = new DeferredCancellation;
            }

            $wait = $failures === 0 ? $this->flushSeconds : min(self::MAX_BACKOFF_SECONDS, 2 ** ($failures - 1));
            $cancellation = $failures === 0 ? new CompositeCancellation($stop, $this->wake->getCancellation()) : $stop;

            try {
                $this->clock->sleep($wait, $cancellation);
            } catch (CancelledException) {
                // Woken early by a full batch or by the stop request.
            }
            if ($stop->isRequested()) {
                break;
            }

            $failures = $this->flush() ? 0 : $failures + 1;
            $this->broadcast(false);
        }
    }

    /**
     * Writes the queued outcomes batch by batch.
     *
     * @return bool False when a batch failed; it and everything after it stays queued.
     */
    public function flush(): bool
    {
        $this->logDropped();

        while ($this->queue !== []) {
            $keys = array_slice(array_keys($this->queue), 0, $this->batchSize);
            $items = [];
            foreach ($keys as $key) {
                $items[] = $this->queue[$key]['item'];
            }

            try {
                $batch = $this->recorder->record($items, false);
            } catch (Throwable $e) {
                $this->failed($e, count($items));

                return false;
            }

            $entries = [];
            foreach ($keys as $key) {
                $entries[] = $this->queue[$key];
                unset($this->queue[$key]);
            }

            foreach ($entries as ['item' => [$spec, $outcome, $checkedAt], 'done' => $done]) {
                $transition = $batch->transitions[$spec->id] ?? null;
                if ($transition !== null) {
                    $this->unbroadcast[$spec->workspaceId][$spec->id] = [
                        'monitor_id' => $spec->id,
                        'status' => $transition->effective->value,
                        'latency_ms' => $outcome->latencyMs,
                        'checked_at' => $checkedAt->toIso8601ZuluString(),
                    ];
                }
                $this->notify($done, $transition);
            }
        }

        return true;
    }

    /**
     * Writes whatever is queued during shutdown, with a few quick retries instead of the open-ended backoff.
     */
    public function drain(int $attempts = 3): void
    {
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($this->flush()) {
                break;
            }
            if ($attempt < $attempts) {
                $this->clock->sleep(2 ** ($attempt - 1));
            }
        }

        if ($this->queue !== []) {
            Log::error('Check worker stopped with unrecorded outcomes: dropped '.count($this->queue).' outcomes', [
                'dropped' => count($this->queue),
            ]);
            $this->queue = [];
        }

        $this->broadcast(true);
    }

    /**
     * Dispatches ChecksRecorded for every workspace whose broadcast interval has passed.
     */
    public function broadcast(bool $force): void
    {
        $now = $this->clock->now();

        foreach ($this->unbroadcast as $workspaceId => $checks) {
            $last = $this->lastBroadcast[$workspaceId] ?? null;
            if (! $force && $last !== null && $last->diffInSeconds($now) < $this->broadcastSeconds) {
                continue;
            }

            $events = [];
            foreach (array_chunk(array_values($checks), ResultRecorder::BROADCAST_CHUNK) as $chunk) {
                $events[] = new ChecksRecorded($workspaceId, $chunk);
            }
            PostCommit::dispatch($events);

            $this->lastBroadcast[$workspaceId] = $now;
            unset($this->unbroadcast[$workspaceId]);
        }
    }

    private function failed(Throwable $e, int $count): void
    {
        $database = $e instanceof QueryException || $e instanceof PDOException;

        Log::log($database ? 'warning' : 'error', 'Flushing check outcomes failed; they stay buffered for a retry.', [
            'exception' => $e::class,
            'message' => mb_substr($e->getMessage(), 0, self::MESSAGE_LOG_LENGTH),
            'batch' => $count,
            'buffered' => count($this->queue),
        ]);

        if (! $database) {
            return;
        }

        try {
            ($this->reconnect)();
        } catch (Throwable $reconnectError) {
            Log::warning('Reconnecting to the database failed.', [
                'exception' => $reconnectError::class,
                'message' => mb_substr($reconnectError->getMessage(), 0, self::MESSAGE_LOG_LENGTH),
            ]);
        }
    }

    private function logDropped(): void
    {
        if ($this->droppedSinceLog === 0) {
            return;
        }

        Log::error("Check outcome buffer is full: dropped {$this->droppedSinceLog} outcomes", [
            'dropped' => $this->droppedSinceLog,
            'capacity' => $this->capacity,
        ]);
        $this->droppedSinceLog = 0;
    }

    /**
     * @param  Closure(?Transition): void  $done
     */
    private function notify(Closure $done, ?Transition $transition): void
    {
        try {
            $done($transition);
        } catch (Throwable $e) {
            Log::error('A recorded outcome callback failed.', ['exception' => $e::class]);
        }
    }
}
