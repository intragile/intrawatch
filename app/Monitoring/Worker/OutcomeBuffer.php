<?php

namespace App\Monitoring\Worker;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use App\Events\ChecksRecorded;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\DatabaseErrors;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Engine\PostCommit;
use App\Monitoring\Engine\RecordedBatch;
use App\Monitoring\Engine\ResultRecorder;
use App\Monitoring\Engine\Transition;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Collects check outcomes and writes them through the ResultRecorder in batches.
 *
 * Outcomes stay queued until a record() call for them has returned, so a failed flush (database gone,
 * locked or restarting) loses nothing and a committed one is never written again. While the database is
 * unavailable the buffer keeps accepting outcomes up to its capacity and then drops the oldest.
 *
 * Each outcome's callback runs once: after it is recorded, or as soon as recording it has failed (or it is
 * dropped), with a null transition. The early call lets the scheduler keep checking during an outage while
 * the outcome itself stays queued. An outcome released early that is later handed back (see below) is
 * reported once more, so the scheduler learns that it was never written.
 *
 * A batch the database rejects for the data it carries (see DatabaseErrors::rejectsData()) is recorded one
 * outcome at a time at once, and only the outcomes it still rejects are dropped, with an error log. Such an
 * error is no outage: the database answered.
 *
 * While deadlines are held back during a database failure (see OutageTracker::holdingSince()), queued outcomes
 * for deadlines that fell due since the failure started are not written but handed back to the scheduler,
 * which evaluates them again: the reports they rely on may have been lost.
 *
 * Writes deferred with defer() run after the outcomes of each successful flush, so they never block them. A
 * failed one is retried with the next flush; after MAX_DEFERRED_ATTEMPTS failed attempts it is dropped and
 * logged. The scheduler uses them to re-anchor deadlines after an outage.
 *
 * ChecksRecorded is broadcast from here rather than by the recorder, at most once per workspace per
 * broadcast interval, carrying the latest check of each monitor since the previous broadcast.
 */
final class OutcomeBuffer
{
    private const MAX_BACKOFF_SECONDS = 30;

    private const DRAIN_RETRY_SECONDS = 0.5;

    /**
     * Consecutive non-database failures of the same batch before its outcomes are recorded one at a time,
     * so a single outcome the recorder cannot store does not block the rest.
     */
    private const ISOLATE_AFTER_FAILURES = 3;

    /**
     * Failed attempts after which a deferred write is dropped, so a write that cannot succeed does not stay
     * pending forever.
     */
    private const MAX_DEFERRED_ATTEMPTS = 10;

    /**
     * Queued outcomes in arrival order, with the callback that learns the monitor's transition.
     *
     * @var array<int, array{item: array{0: MonitorSpec, 1: CheckOutcome, 2: CarbonImmutable, 3: ?int}, done: Closure(?Transition, bool): void, notified: bool}>
     */
    private array $queue = [];

    /** Whether the last flush attempt failed; outcomes pushed meanwhile are released to the scheduler at once. */
    private bool $failing = false;

    private ?int $failingBatch = null;

    private readonly OutageTracker $outages;

    /**
     * Writes waiting for the next flush, in the order they were deferred.
     *
     * @var array<int, array{write: Closure(): void, dropped: ?Closure(): void, attempts: int}>
     */
    private array $deferred = [];

    private int $deferredSequence = 0;

    private int $batchFailures = 0;

    private int $sequence = 0;

    private int $droppedSinceLog = 0;

    private float $maxFlushSeconds = 0.0;

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
     * @param  ?Closure(): mixed  $afterFlush  Further writes made right after each successful flush, such as cached
     *                                         domain lookups; it must not throw.
     */
    public function __construct(
        private readonly ResultRecorder $recorder,
        private readonly Clock $clock,
        private readonly int $capacity = 10_000,
        private readonly int $batchSize = 500,
        private readonly float $flushSeconds = 1.0,
        private readonly float $broadcastSeconds = 5.0,
        ?Closure $reconnect = null,
        private readonly ?Closure $afterFlush = null,
        ?OutageTracker $outages = null,
    ) {
        $this->outages = $outages ?? new OutageTracker;
        $this->reconnect = $reconnect ?? static function (): void {
            DB::reconnect();
        };
        $this->wake = new DeferredCancellation;
    }

    /**
     * @param  Closure(?Transition, bool): void  $done  Called with the monitor's transition when the outcome is recorded
     *                                                  (null when the recorder skipped it), or with null as soon as
     *                                                  recording it failed or it was dropped. The second argument is
     *                                                  true when the outcome was handed back unrecorded because its
     *                                                  deadline is held back; that call can follow an earlier one.
     */
    public function push(MonitorSpec $spec, CheckOutcome $outcome, CarbonImmutable $checkedAt, ?int $driftMs, Closure $done): void
    {
        $key = ++$this->sequence;
        $this->queue[$key] = ['item' => [$spec, $outcome, $checkedAt, $driftMs], 'done' => $done, 'notified' => false];
        if ($this->failing) {
            $this->release($key, null);
        }

        while (count($this->queue) > $this->capacity && ($oldest = array_key_first($this->queue)) !== null) {
            $this->release($oldest, null);
            unset($this->queue[$oldest]);
            $this->droppedSinceLog++;
        }

        if (count($this->queue) >= $this->batchSize) {
            $this->wake->cancel();
        }
    }

    /**
     * Runs $write after the outcomes of the next successful flush, and again with each flush after that until
     * it succeeds or has failed MAX_DEFERRED_ATTEMPTS times; then it is dropped and $dropped is called. It must
     * be idempotent: a write whose commit was lost on the way may run twice.
     *
     * @param  Closure(): void  $write
     * @param  ?Closure(): void  $dropped
     */
    public function defer(Closure $write, ?Closure $dropped = null): void
    {
        $this->deferred[++$this->deferredSequence] = ['write' => $write, 'dropped' => $dropped, 'attempts' => 0];
        $this->wake->cancel();
    }

    /**
     * The tracker of database failures, shared with the scheduler: this buffer reports its outcome writes, the
     * scheduler its deadline reads and heartbeat writes.
     */
    public function outages(): OutageTracker
    {
        return $this->outages;
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

            if ($this->flush()) {
                $failures = 0;
                $this->afterFlush?->__invoke();
            } else {
                $failures++;
            }
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
        $started = hrtime(true);
        try {
            return $this->writeQueued();
        } finally {
            $this->maxFlushSeconds = max($this->maxFlushSeconds, (hrtime(true) - $started) / 1e9);
        }
    }

    /**
     * Longest wall-clock duration of one flush so far, in milliseconds.
     */
    public function maxFlushMs(): int
    {
        return (int) round($this->maxFlushSeconds * 1000);
    }

    private function writeQueued(): bool
    {
        $this->logDropped();
        $this->handBackHeld();
        $wrote = $this->queue !== [];

        while ($this->queue !== []) {
            $keys = array_slice(array_keys($this->queue), 0, $this->batchSize);

            try {
                $batch = $this->recorder->record($this->items($keys), false);
            } catch (Throwable $e) {
                $rejected = DatabaseErrors::rejectsData($e);
                $this->failed($e, count($keys), $rejected);
                if (DatabaseErrors::isDatabaseError($e) && ! $rejected) {
                    $this->outages->writeFailed($this->clock->now(), $this->earliestDue($keys));

                    return $this->markFailing();
                }
                if (! $rejected && ! $this->countBatchFailure($keys[0])) {
                    return $this->markFailing();
                }
                if (! $this->recordOneByOne($keys)) {
                    return $this->markFailing();
                }

                continue;
            }

            $this->completed($keys, $batch);
        }

        // Only a write proves the database is reachable; an empty queue touches nothing.
        if ($wrote) {
            $this->outages->writeSucceeded($this->clock->now());
        }
        $this->failing = false;
        $this->failingBatch = null;
        $this->batchFailures = 0;
        $this->runDeferred();

        return true;
    }

    /**
     * Writes whatever is queued during shutdown. Failed flushes are retried every half second instead of
     * with the open-ended backoff, and no new attempt starts once $budgetSeconds have passed, so the
     * process stops within the container's stop grace period.
     *
     * @return bool False when outcomes had to be dropped because the database did not take them.
     */
    public function drain(float $budgetSeconds = 2.0): bool
    {
        $deadline = $this->timestamp() + $budgetSeconds;
        while (true) {
            if ($this->flush()) {
                $this->afterFlush?->__invoke();
                break;
            }
            $remaining = $deadline - $this->timestamp();
            if ($remaining <= 0) {
                break;
            }
            $this->clock->sleep(min(self::DRAIN_RETRY_SECONDS, $remaining));
        }

        if ($this->queue !== []) {
            Log::error('Check worker stopped with unrecorded outcomes: dropped '.count($this->queue).' outcomes', [
                'dropped' => count($this->queue),
            ]);
            $this->queue = [];
            $this->broadcast(true);

            return false;
        }

        $this->broadcast(true);

        return true;
    }

    /**
     * Dispatches ChecksRecorded for every workspace whose broadcast interval has passed.
     */
    public function broadcast(bool $force): void
    {
        $now = $this->clock->now();
        $events = [];

        foreach ($this->unbroadcast as $workspaceId => $checks) {
            $last = $this->lastBroadcast[$workspaceId] ?? null;
            if (! $force && $last !== null && $last->diffInSeconds($now) < $this->broadcastSeconds) {
                continue;
            }

            foreach (array_chunk(array_values($checks), ResultRecorder::BROADCAST_CHUNK) as $chunk) {
                $events[] = new ChecksRecorded($workspaceId, $chunk);
            }

            $this->lastBroadcast[$workspaceId] = $now;
            unset($this->unbroadcast[$workspaceId]);
        }

        // One queued job for every workspace due now (see PostCommit).
        PostCommit::dispatch($events);
    }

    /**
     * @param  list<int>  $keys
     * @return list<array{0: MonitorSpec, 1: CheckOutcome, 2: CarbonImmutable, 3: ?int}>
     */
    private function items(array $keys): array
    {
        $items = [];
        foreach ($keys as $key) {
            $items[] = $this->queue[$key]['item'];
        }

        return $items;
    }

    /**
     * @param  list<int>  $keys
     */
    private function completed(array $keys, RecordedBatch $batch): void
    {
        foreach ($keys as $key) {
            [$spec, $outcome, $checkedAt] = $this->queue[$key]['item'];
            $transition = $batch->transitions[$spec->id] ?? null;
            if ($transition !== null) {
                $this->unbroadcast[$spec->workspaceId][$spec->id] = [
                    'monitor_id' => $spec->id,
                    'status' => $transition->effective->value,
                    'latency_ms' => $outcome->latencyMs,
                    'checked_at' => $checkedAt->toIso8601ZuluString(),
                ];
            }
            $this->release($key, $transition);
            unset($this->queue[$key]);
        }

        if ($batch->groupTransitions !== []) {
            $now = $this->clock->now();
            foreach ($batch->groupTransitions as $transition) {
                $this->unbroadcast[$transition->workspaceId][$transition->monitorId] = ResultRecorder::groupCheck($transition, $now);
            }
        }
    }

    /**
     * @return bool Whether the batch has now failed often enough to be recorded one outcome at a time.
     */
    private function countBatchFailure(int $firstKey): bool
    {
        if ($this->failingBatch === $firstKey) {
            $this->batchFailures++;
        } else {
            $this->failingBatch = $firstKey;
            $this->batchFailures = 1;
        }

        return $this->batchFailures >= self::ISOLATE_AFTER_FAILURES;
    }

    /**
     * Records the outcomes of a failing batch one by one and drops the ones that still fail, unless the
     * database is unavailable.
     *
     * @param  list<int>  $keys
     * @return bool False when the database failed meanwhile; the remaining outcomes stay queued.
     */
    private function recordOneByOne(array $keys): bool
    {
        foreach ($keys as $key) {
            try {
                $batch = $this->recorder->record($this->items([$key]), false);
            } catch (Throwable $e) {
                $rejected = DatabaseErrors::rejectsData($e);
                if (DatabaseErrors::isDatabaseError($e) && ! $rejected) {
                    $this->failed($e, 1, false);
                    $this->outages->writeFailed($this->clock->now(), $this->earliestDue([$key]));

                    return false;
                }

                // No message or payload: both can carry data from the checked target.
                Log::error($rejected ? 'Dropped a check outcome the database rejects.' : 'Dropped a check outcome the recorder cannot store.', [
                    'monitor_id' => $this->queue[$key]['item'][0]->id,
                    ...DatabaseErrors::context($e),
                ]);
                $this->release($key, null);
                unset($this->queue[$key]);

                continue;
            }

            $this->completed([$key], $batch);
        }

        $this->failingBatch = null;
        $this->batchFailures = 0;

        return true;
    }

    /**
     * Releases every queued outcome to the scheduler so checks go on while the outcomes wait for a retry.
     */
    private function markFailing(): bool
    {
        $this->failing = true;
        foreach (array_keys($this->queue) as $key) {
            $this->release($key, null);
        }

        return false;
    }

    /**
     * Hands the queued outcomes of deadlines that fell due since the start of a database failure back to the
     * scheduler instead of writing them, while deadlines are held back.
     */
    private function handBackHeld(): void
    {
        $since = $this->outages->holdingSince();
        if ($since === null) {
            return;
        }

        foreach ($this->queue as $key => $entry) {
            $due = $entry['item'][1]->deadline?->dueAt;
            if ($due !== null && $due->getTimestamp() >= $since->getTimestamp()) {
                $this->release($key, null, true);
                unset($this->queue[$key]);
            }
        }
    }

    /**
     * Runs the deferred writes in order and drops each one that succeeded. The first one that fails stops
     * the run: it and the ones after it are tried again with the next flush, unless it has now failed
     * MAX_DEFERRED_ATTEMPTS times, in which case it is dropped.
     */
    private function runDeferred(): void
    {
        foreach ($this->deferred as $key => $deferred) {
            try {
                ($deferred['write'])();
            } catch (Throwable $e) {
                $attempts = $deferred['attempts'] + 1;
                $this->deferred[$key]['attempts'] = $attempts;
                $this->deferredFailed($e, $attempts);
                if ($attempts < self::MAX_DEFERRED_ATTEMPTS) {
                    return;
                }

                unset($this->deferred[$key]);
                Log::error('Dropped a deferred database write after '.self::MAX_DEFERRED_ATTEMPTS.' failed attempts.', DatabaseErrors::context($e));
                try {
                    ($deferred['dropped'] ?? static fn () => null)();
                } catch (Throwable $callbackError) {
                    Log::error('A dropped deferred write callback failed.', ['exception' => $callbackError::class]);
                }

                continue;
            }
            unset($this->deferred[$key]);
        }
    }

    private function deferredFailed(Throwable $e, int $attempts): void
    {
        $database = DatabaseErrors::isDatabaseError($e);

        Log::log($database ? 'warning' : 'error', 'A deferred database write failed; it is retried with the next flush.', [
            ...DatabaseErrors::context($e),
            'attempt' => $attempts,
        ]);

        if ($database) {
            $this->reconnectQuietly();
        }
    }

    /**
     * When the earliest of the given outcomes that report a missed deadline fell due, if any of them does.
     *
     * @param  list<int>  $keys
     */
    private function earliestDue(array $keys): ?CarbonImmutable
    {
        $earliest = null;
        foreach ($keys as $key) {
            $due = $this->queue[$key]['item'][1]->deadline?->dueAt;
            if ($due !== null && ($earliest === null || $due->lt($earliest))) {
                $earliest = $due;
            }
        }

        return $earliest;
    }

    /**
     * Logs a failed batch by the class, SQLSTATE and driver code of the error, never its message: driver
     * messages can quote outcome data.
     */
    private function failed(Throwable $e, int $count, bool $rejected): void
    {
        $unavailable = DatabaseErrors::isDatabaseError($e) && ! $rejected;

        Log::log(DatabaseErrors::isDatabaseError($e) ? 'warning' : 'error', $rejected
            ? 'The database rejected the data of a batch of check outcomes; recording them one by one.'
            : 'Flushing check outcomes failed; they stay buffered for a retry.', [
                ...DatabaseErrors::context($e),
                'batch' => $count,
                'buffered' => count($this->queue),
            ]);

        if ($unavailable) {
            $this->reconnectQuietly();
        }
    }

    private function reconnectQuietly(): void
    {
        try {
            ($this->reconnect)();
        } catch (Throwable $reconnectError) {
            Log::warning('Reconnecting to the database failed.', DatabaseErrors::context($reconnectError));
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
     * Runs the outcome's callback unless it already ran; a hand-back is reported even then.
     */
    private function release(int $key, ?Transition $transition, bool $handedBack = false): void
    {
        $entry = $this->queue[$key] ?? null;
        if ($entry === null || ($entry['notified'] && ! $handedBack)) {
            return;
        }
        $this->queue[$key]['notified'] = true;

        try {
            ($entry['done'])($transition, $handedBack);
        } catch (Throwable $e) {
            Log::error('A recorded outcome callback failed.', ['exception' => $e::class]);
        }
    }

    private function timestamp(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
