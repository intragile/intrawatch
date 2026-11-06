<?php

namespace App\Monitoring\Worker;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\Sync\LocalSemaphore;
use Amp\TimeoutCancellation;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Monitoring\Checks\CheckerRegistry;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\Deadline;
use App\Monitoring\Engine\DeadlineEvaluator;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Engine\Transition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use SplMinHeap;
use Throwable;

use function Amp\async;

/**
 * Runs the checks of one shard on the event loop.
 *
 * Due times live in a min-heap with lazy deletion: rescheduling a monitor pushes a new entry and makes the
 * old one stale. A monitor has either a heap entry or a check in flight, never both, so it never overlaps
 * itself; its next due time is set only once its outcome has been recorded.
 *
 * Nothing on the event loop may block on network I/O. The only blocking calls allowed are database calls
 * made by these collaborators:
 * - MonitorLoader: loading the shard and polling it for changes;
 * - OutcomeBuffer, through the ResultRecorder: writing outcomes, and the writes deferred to its flushes;
 * - Heartbeat: writing the worker's heartbeat row;
 * - DatabaseDeadlineEvaluator: reading the state rows of the push and cron monitors due in one tick, in
 *   one batched query; its re-anchoring writes run only as deferred writes of the OutcomeBuffer's flush.
 *
 * Push and cron deadlines that passed while this worker could not see reports, before it started or
 * during a database outage (see OutageTracker), are re-anchored in the database: the anchor of each one
 * without a recorded miss is moved to the end of that blind spot in one batched update at the next flush.
 * Until that update has succeeded or been dropped, outcomes for deadlines inside the window are held back
 * and evaluated again later; afterwards evaluation reads the moved anchors, so no window has to be remembered.
 * While a failure goes on, deadlines due since it started are held back too: throughout a full failure, but
 * for at most OutageTracker::MAX_HOLD_SECONDS of partial failure, after which they are evaluated and recorded
 * as usual. Only deadline reads and outcome writes start failures; heartbeat and poll errors never do, so a
 * query that keeps failing there never holds back a down.
 */
final class Scheduler
{
    /**
     * Extra time a checker gets beyond its own timeout before the scheduler cancels it.
     */
    private const SAFETY_MARGIN_SECONDS = 2;

    /**
     * Longest sleep of the dispatch loop, so a missed wake-up costs at most this much drift.
     */
    private const MAX_IDLE_SECONDS = 1.0;

    private const MAX_LOAD_BACKOFF_SECONDS = 30;

    /**
     * Longest wait before push and cron deadlines are evaluated again after their state could not be read.
     */
    private const DEADLINE_RETRY_SECONDS = 10;

    private readonly LocalSemaphore $slots;

    /** @var array<int, MonitorSpec> */
    private array $specs = [];

    /**
     * Load generation per monitor. A check whose generation no longer matches was started before the
     * monitor was removed (and possibly added again), so its outcome is discarded.
     *
     * @var array<int, int>
     */
    private array $generations = [];

    private int $generation = 0;

    /** @var SplMinHeap<array{0: float, 1: int, 2: int}> Due time, entry sequence, monitor id. */
    private SplMinHeap $heap;

    /** @var array<int, int> The live heap entry of each scheduled monitor. */
    private array $entries = [];

    /** @var array<int, float> */
    private array $dueAt = [];

    private int $sequence = 0;

    /** @var array<int, true> Monitors with a check in flight or an outcome waiting to be recorded. */
    private array $busy = [];

    /** @var array<int, true> Busy monitors with a manual check request waiting. */
    private array $requested = [];

    /** @var array<int, array{at: float, retrying: bool}> Start of the last recorded check per monitor. */
    private array $lastRun = [];

    /** @var array<int, Future<void>> */
    private array $running = [];

    private int $runSequence = 0;

    /** @var array<string, true> */
    private array $warnedTypes = [];

    private DeferredCancellation $wake;

    private float $sleepingUntil = INF;

    private DeferredCancellation $abort;

    /**
     * Re-anchoring windows whose update has not succeeded yet, as [start or null, end] Unix timestamps.
     *
     * @var array<int, array{0: ?int, 1: int}>
     */
    private array $pendingReanchors = [];

    private int $reanchorSequence = 0;

    public function __construct(
        private readonly int $shard,
        private readonly int $shards,
        private readonly MonitorLoader $loader,
        private readonly OutcomeBuffer $buffer,
        private readonly Heartbeat $heartbeat,
        private readonly CheckerRegistry $checkers,
        private readonly DeadlineEvaluator $deadlines,
        private readonly Clock $clock,
        int $concurrency = 200,
        private readonly float $pollSeconds = 5.0,
        private readonly float $heartbeatSeconds = 10.0,
        private readonly float $shutdownGraceSeconds = 4.0,
    ) {
        $this->slots = new LocalSemaphore(max(1, $concurrency));
        $this->heap = new SplMinHeap;
        $this->wake = new DeferredCancellation;
        $this->abort = new DeferredCancellation;
        $this->buffer->outages()->onOutageEnded(function (CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $end): void {
            Log::warning('The database was unavailable; push and cron deadlines that fell due meanwhile are re-anchored.', [
                'shard' => $this->shard,
                'from' => $from->toIso8601ZuluString(),
                'to' => $to->toIso8601ZuluString(),
                'ended' => $end->toIso8601ZuluString(),
            ]);
            $this->reanchor($from, $to);
        });
    }

    /**
     * Runs until $stop is cancelled, then waits for checks in flight, flushes and returns.
     */
    public function run(Cancellation $stop): void
    {
        if (! $this->load($stop)) {
            return;
        }
        // Reports that arrived before this worker started were never seen by it.
        $this->reanchor(null, $this->clock->now());

        $flushStop = new DeferredCancellation;
        $flusher = async(fn () => $this->buffer->run($flushStop->getCancellation()));
        $poller = async(fn () => $this->pollLoop($stop));
        $beater = async(fn () => $this->heartbeatLoop($stop));

        $this->dispatchLoop($stop);

        $poller->await();
        $beater->await();
        $this->awaitInFlight();

        $flushStop->cancel();
        $flusher->await();
        // The last heartbeat is skipped when the database did not take the outcomes: it would only spend
        // more of the stop grace period waiting for it.
        if ($this->buffer->drain()) {
            $this->heartbeat->beat(count($this->specs), $this->buffer->count());
        }
    }

    /**
     * @return bool False when stopped before the shard could be loaded.
     */
    private function load(Cancellation $stop): bool
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $specs = $this->loader->loadShard($this->shard, $this->shards);
                break;
            } catch (Throwable $e) {
                Log::warning('Loading the monitors of the shard failed; retrying.', [
                    'shard' => $this->shard,
                    'exception' => $e::class,
                    'attempt' => $attempt,
                ]);
                try {
                    $this->clock->sleep(min(self::MAX_LOAD_BACKOFF_SECONDS, 2 ** ($attempt - 1)), $stop);
                } catch (CancelledException) {
                    return false;
                }
            }
        }

        $now = $this->timestamp();
        foreach ($specs as $id => $spec) {
            $this->specs[$id] = $spec;
            $this->generations[$id] = ++$this->generation;
            // Spreading the first checks over one interval avoids a thundering herd on start. Deadlines are
            // evaluated right away: they are cheap and read together in one batch.
            $this->schedule($id, $this->mode($spec) === 'deadline'
                ? $now
                : $now + (crc32((string) $id) % max(1, $spec->intervalSeconds)));
        }
        foreach ($this->loader->requestedOnLoad() as $id) {
            $this->schedule($id, $now);
        }

        return true;
    }

    private function dispatchLoop(Cancellation $stop): void
    {
        while (! $stop->isRequested()) {
            $now = $this->timestamp();
            /** @var array<int, float> $deadlines Due time per monitor. */
            $deadlines = [];
            while (! $this->heap->isEmpty()) {
                [$due, $entry, $id] = $this->heap->top();
                if (($this->entries[$id] ?? null) !== $entry) {
                    $this->heap->extract();

                    continue;
                }
                if ($due > $now) {
                    break;
                }
                $this->heap->extract();
                unset($this->entries[$id], $this->dueAt[$id]);
                $spec = $this->specs[$id] ?? null;
                if ($spec !== null && $this->mode($spec) === 'deadline') {
                    $deadlines[$id] = $due;
                } else {
                    $this->launch($id, $due);
                }
            }
            if ($deadlines !== []) {
                $this->launchDeadlines($deadlines);
            }

            $next = $this->nextDue();
            $wait = $next === null ? self::MAX_IDLE_SECONDS : min(self::MAX_IDLE_SECONDS, max(0.0, $next - $now));

            if ($this->wake->isCancelled()) {
                $this->wake = new DeferredCancellation;
            }
            $this->sleepingUntil = $now + $wait;
            try {
                $this->clock->sleep($wait, new CompositeCancellation($stop, $this->wake->getCancellation()));
            } catch (CancelledException) {
                // Woken early by an earlier due time or by the stop request.
            }
            $this->sleepingUntil = INF;
        }
    }

    private function pollLoop(Cancellation $stop): void
    {
        while (true) {
            try {
                $this->clock->sleep($this->pollSeconds, $stop);
            } catch (CancelledException) {
                return;
            }

            try {
                $this->apply($this->loader->changesSince($this->shard, $this->shards));
            } catch (Throwable $e) {
                Log::warning('Polling monitor changes failed; retrying on the next poll.', [
                    'shard' => $this->shard,
                    'exception' => $e::class,
                ]);
            }
        }
    }

    private function heartbeatLoop(Cancellation $stop): void
    {
        $outages = $this->buffer->outages();

        while (true) {
            // A heartbeat that was written proves writes work, which can end a failure on a shard whose outcomes
            // are all held back. A failed one only tells a full failure from a partial one; it starts none.
            $error = $this->heartbeat->beat(count($this->specs), $this->buffer->count());
            if ($error === null) {
                $outages->writeSucceeded($this->clock->now());
            } elseif (OutageTracker::isDatabaseError($error)) {
                $outages->heartbeatFailed($this->clock->now());
            }
            if ($outages->failingSince() !== null) {
                $this->expediteDeadlines();
            }
            try {
                $this->clock->sleep($this->heartbeatSeconds, $stop);
            } catch (CancelledException) {
                return;
            }
        }
    }

    /**
     * While the database is failing, evaluates every push and cron deadline within the retry delay, so a
     * successful read that ends the failure follows soon even on a shard whose deadlines are far apart.
     */
    private function expediteDeadlines(): void
    {
        $soon = $this->timestamp() + self::DEADLINE_RETRY_SECONDS;
        foreach ($this->specs as $id => $spec) {
            if (! isset($this->busy[$id]) && ($this->dueAt[$id] ?? 0.0) > $soon && $this->mode($spec) === 'deadline') {
                $this->schedule($id, $soon);
            }
        }
    }

    /**
     * Re-anchors the deadlines of the shard that fell due in [$from, $to) at the next flush, retried with
     * every flush until it succeeds or is dropped. Outcomes for deadlines inside the window are held back until
     * then.
     */
    private function reanchor(?CarbonImmutable $from, CarbonImmutable $to): void
    {
        $key = ++$this->reanchorSequence;
        $this->pendingReanchors[$key] = [$from?->getTimestamp(), $to->getTimestamp()];

        $this->buffer->defer(function () use ($key, $from, $to): void {
            $specs = [];
            foreach ($this->specs as $spec) {
                if ($spec->type === MonitorType::Push || $spec->type === MonitorType::Cron) {
                    $specs[] = $spec;
                }
            }
            $moved = $specs === [] ? 0 : $this->deadlines->reanchor($specs, $from, $to);
            unset($this->pendingReanchors[$key]);
            if ($moved > 0) {
                Log::info('Re-anchored push and cron deadlines the worker could not see.', [
                    'shard' => $this->shard,
                    'monitors' => $moved,
                    'from' => $from?->toIso8601ZuluString(),
                    'to' => $to->toIso8601ZuluString(),
                ]);
            }
        }, function () use ($key): void {
            unset($this->pendingReanchors[$key]);
        });
    }

    /**
     * Whether an outcome for this deadline must wait: the database is failing since before it and the failure
     * still holds deadlines back (see OutageTracker), or it lies in a window whose re-anchoring is still pending.
     */
    private function withheld(Deadline $deadline, CarbonImmutable $now): bool
    {
        $at = $deadline->at->getTimestamp();
        $holdingSince = $this->buffer->outages()->holdingSince($now);
        if ($holdingSince !== null && $at >= $holdingSince->getTimestamp()) {
            return true;
        }
        foreach ($this->pendingReanchors as [$from, $to]) {
            if ($at < $to && ($from === null || $at >= $from)) {
                return true;
            }
        }

        return false;
    }

    private function apply(ShardChanges $changes): void
    {
        $now = $this->timestamp();

        foreach ($changes->removed as $id) {
            unset($this->specs[$id], $this->generations[$id], $this->entries[$id], $this->dueAt[$id],
                $this->requested[$id], $this->lastRun[$id]);
        }

        foreach ($changes->added as $id => $spec) {
            $this->specs[$id] = $spec;
            $this->generations[$id] = ++$this->generation;
            if (! isset($this->busy[$id])) {
                $this->schedule($id, $now);
            }
        }

        foreach ($changes->changed as $id => $spec) {
            if (! isset($this->specs[$id])) {
                continue;
            }
            $this->specs[$id] = $spec;
            if (isset($this->busy[$id])) {
                // The new settings apply when the running check's outcome reschedules the monitor.
                continue;
            }
            $last = $this->lastRun[$id] ?? null;
            if ($last !== null) {
                $delay = $last['retrying'] ? $spec->retryIntervalSeconds : $spec->intervalSeconds;
                $this->schedule($id, max($now, $last['at'] + $delay));
            } else {
                $this->schedule($id, min($this->dueAt[$id] ?? $now, $now + $spec->intervalSeconds));
            }
        }

        foreach ($changes->requested as $id) {
            if (! isset($this->specs[$id])) {
                continue;
            }
            if (isset($this->busy[$id])) {
                $this->requested[$id] = true;
            } else {
                $this->schedule($id, $now);
            }
        }
    }

    /**
     * Puts the monitor on the heap, replacing any earlier entry. Monitors that are not evaluated on a
     * schedule (groups, or types without a checker) are left out.
     */
    private function schedule(int $id, float $due): void
    {
        $spec = $this->specs[$id] ?? null;
        if ($spec === null || $this->mode($spec) === null) {
            unset($this->entries[$id], $this->dueAt[$id]);

            return;
        }

        $entry = ++$this->sequence;
        $this->entries[$id] = $entry;
        $this->dueAt[$id] = $due;
        $this->heap->insert([$due, $entry, $id]);

        if ($due < $this->sleepingUntil) {
            $this->wake->cancel();
        }
    }

    /**
     * @return 'check'|'deadline'|null
     */
    private function mode(MonitorSpec $spec): ?string
    {
        if ($this->checkers->supports($spec->type)) {
            return 'check';
        }
        if ($spec->type === MonitorType::Push || $spec->type === MonitorType::Cron) {
            return 'deadline';
        }
        if ($spec->type->definition()->isScheduled() && ! isset($this->warnedTypes[$spec->type->value])) {
            $this->warnedTypes[$spec->type->value] = true;
            Log::warning("No checker is registered for {$spec->type->value} monitors; they are not checked.");
        }

        return null;
    }

    private function launch(int $id, float $due): void
    {
        $spec = $this->specs[$id] ?? null;
        $generation = $this->generations[$id] ?? null;
        if ($spec === null || $generation === null) {
            return;
        }

        $this->busy[$id] = true;
        $key = ++$this->runSequence;
        $this->running[$key] = async(function () use ($spec, $generation, $due, $key): void {
            try {
                $this->runCheck($spec, $generation, $due);
            } catch (Throwable $e) {
                Log::error('A scheduled check crashed.', ['monitor_id' => $spec->id, 'exception' => $e::class]);
                $this->finish($spec->id, $generation, $this->timestamp(), $spec, null, null);
            } finally {
                unset($this->running[$key]);
            }
        });
    }

    private function runCheck(MonitorSpec $spec, int $generation, float $due): void
    {
        $lock = $this->slots->acquire();
        try {
            if (! $this->isCurrent($spec->id, $generation) || $this->aborted()) {
                $this->discard($spec->id, $generation);

                return;
            }

            $startedAt = $this->clock->now();
            $started = $this->timestampOf($startedAt);
            $driftMs = max(0, (int) round(($started - $due) * 1000));
            $this->heartbeat->recordCheck($startedAt, $driftMs);

            $cancellation = new CompositeCancellation(
                new TimeoutCancellation($spec->timeoutSeconds + self::SAFETY_MARGIN_SECONDS),
                $this->abort->getCancellation(),
            );
            try {
                $outcome = $this->checkers->for($spec->type)->check($spec, $cancellation);
            } catch (CancelledException) {
                $outcome = CheckOutcome::down("Timed out after {$spec->timeoutSeconds} s");
            } catch (Throwable $e) {
                $outcome = CheckOutcome::fromThrowable($e);
            }
        } finally {
            $lock->release();
        }

        // A monitor removed while its check ran records nothing, and neither does a check cut off by shutdown.
        if (! $this->isCurrent($spec->id, $generation) || $this->aborted()) {
            $this->discard($spec->id, $generation);

            return;
        }

        $this->buffer->push($spec, $outcome, $startedAt, $driftMs,
            fn (?Transition $transition) => $this->finish($spec->id, $generation, $started, $spec, $outcome, $transition));
    }

    /**
     * Evaluates the push and cron monitors that fell due in the same tick together, so their state is read
     * in one batch.
     *
     * @param  array<int, float>  $due  Due time per monitor.
     */
    private function launchDeadlines(array $due): void
    {
        /** @var array<int, array{0: MonitorSpec, 1: int, 2: float}> $batch */
        $batch = [];
        foreach ($due as $id => $at) {
            $spec = $this->specs[$id] ?? null;
            $generation = $this->generations[$id] ?? null;
            if ($spec !== null && $generation !== null) {
                $this->busy[$id] = true;
                $batch[$id] = [$spec, $generation, $at];
            }
        }
        if ($batch === []) {
            return;
        }

        $key = ++$this->runSequence;
        $this->running[$key] = async(function () use ($batch, $key): void {
            try {
                $this->evaluateDeadlines($batch);
            } catch (Throwable $e) {
                Log::error('Evaluating push and cron deadlines crashed.', ['shard' => $this->shard, 'exception' => $e::class]);
                $now = $this->timestamp();
                foreach ($batch as $id => [$spec, $generation]) {
                    if (isset($this->busy[$id])) {
                        $this->release($id, $generation, $now + min($spec->intervalSeconds, self::DEADLINE_RETRY_SECONDS));
                    }
                }
            } finally {
                unset($this->running[$key]);
            }
        });
    }

    /**
     * @param  array<int, array{0: MonitorSpec, 1: int, 2: float}>  $batch
     */
    private function evaluateDeadlines(array $batch): void
    {
        $now = $this->clock->now();
        $nowAt = $this->timestampOf($now);
        $specs = [];
        $earliest = INF;
        foreach ($batch as [$spec, , $due]) {
            $specs[] = $spec;
            $earliest = min($earliest, $due);
        }

        $outages = $this->buffer->outages();
        try {
            $results = $this->deadlines->evaluateMany($specs, $now);
        } catch (Throwable $e) {
            if (OutageTracker::isDatabaseError($e)) {
                // A deadline is evaluated one second after it passed, so the failure covers the deadlines
                // whose own read failed.
                $outages->readFailed($now, CarbonImmutable::createFromTimestamp((int) floor($earliest) - 1, 'UTC'));
            }
            // Without the state rows nothing is known, so nothing is recorded: a database outage never turns
            // into a missed heartbeat.
            Log::warning('Reading push and cron deadlines failed; retrying.', [
                'shard' => $this->shard,
                'monitors' => count($batch),
                'exception' => $e::class,
            ]);
            foreach ($batch as $id => [$spec, $generation]) {
                $this->release($id, $generation, $nowAt + min($spec->intervalSeconds, self::DEADLINE_RETRY_SECONDS));
            }

            return;
        }
        $outages->readSucceeded($now);

        foreach ($batch as $id => [$spec, $generation]) {
            [$outcome, $next] = $results[$id] ?? [null, $now->addSeconds($spec->intervalSeconds)];
            $nextAt = max($nowAt + 1, $this->timestampOf($next));

            if ($outcome === null || ! $this->isCurrent($id, $generation)) {
                $this->release($id, $generation, $nextAt);

                continue;
            }
            if ($outcome->deadline !== null && $this->withheld($outcome->deadline, $now)) {
                // Its reports may have been lost; it is evaluated again once the anchor has been moved.
                $this->release($id, $generation, $nowAt + min($spec->intervalSeconds, self::DEADLINE_RETRY_SECONDS));

                continue;
            }

            $this->buffer->push($spec, $outcome, $now, null, fn () => $this->release($id, $generation, $nextAt));
        }
    }

    /**
     * Ends a deadline evaluation: the monitor is evaluated again at $nextAt.
     */
    private function release(int $id, int $generation, float $nextAt): void
    {
        unset($this->busy[$id]);
        if ($this->isCurrent($id, $generation)) {
            $this->schedule($id, $nextAt);
        } else {
            $this->rescheduleReadded($id);
        }
    }

    /**
     * Reschedules a monitor once its outcome was recorded. A null transition means the outcome was not
     * recorded (yet): recording failed, it was dropped or skipped. The delay then follows the raw outcome,
     * the retry interval after a failure and the regular interval otherwise.
     */
    private function finish(int $id, int $generation, float $started, MonitorSpec $spec, ?CheckOutcome $outcome, ?Transition $transition): void
    {
        unset($this->busy[$id]);

        if (! $this->isCurrent($id, $generation)) {
            $this->rescheduleReadded($id);

            return;
        }

        $current = $this->specs[$id];
        $retrying = $transition !== null
            ? $transition->effective === MonitorStatus::Pending
            : $outcome?->status === MonitorStatus::Down;
        $delay = $transition !== null && $current === $spec
            ? $transition->nextDelaySeconds
            : ($retrying ? $current->retryIntervalSeconds : $current->intervalSeconds);
        $this->lastRun[$id] = ['at' => $started, 'retrying' => $retrying];

        $now = $this->timestamp();
        if (isset($this->requested[$id])) {
            unset($this->requested[$id]);
            $this->schedule($id, $now);

            return;
        }

        $this->schedule($id, max($now, $started + $delay));
    }

    private function discard(int $id, int $generation): void
    {
        unset($this->busy[$id]);
        if (! $this->isCurrent($id, $generation)) {
            $this->rescheduleReadded($id);
        }
    }

    /**
     * A monitor removed and added back while its old check was in flight waits for that check, then runs.
     */
    private function rescheduleReadded(int $id): void
    {
        if (isset($this->specs[$id]) && ! isset($this->entries[$id])) {
            $this->schedule($id, $this->timestamp());
        }
    }

    private function isCurrent(int $id, int $generation): bool
    {
        return ($this->generations[$id] ?? null) === $generation;
    }

    /**
     * @phpstan-impure
     */
    private function aborted(): bool
    {
        return $this->abort->isCancelled();
    }

    private function nextDue(): ?float
    {
        while (! $this->heap->isEmpty()) {
            [$due, $entry, $id] = $this->heap->top();
            if (($this->entries[$id] ?? null) === $entry) {
                return $due;
            }
            $this->heap->extract();
        }

        return null;
    }

    /**
     * Waits up to the grace period for checks in flight, then cancels the rest. The grace period and the
     * buffer's drain budget together stay well inside the 10 s that `docker stop` allows by default.
     */
    private function awaitInFlight(): void
    {
        $deadline = $this->timestamp() + $this->shutdownGraceSeconds;
        while ($this->running !== [] && ($remaining = $deadline - $this->timestamp()) > 0) {
            $this->clock->sleep(min(0.1, $remaining));
        }

        if ($this->running !== []) {
            Log::warning('Check worker stopped with checks still running; their outcomes are discarded.', [
                'shard' => $this->shard,
                'running' => count($this->running),
            ]);
        }
        $this->abort->cancel();
    }

    private function timestamp(): float
    {
        return $this->timestampOf($this->clock->now());
    }

    private function timestampOf(CarbonImmutable $at): float
    {
        return (float) $at->format('U.u');
    }
}
