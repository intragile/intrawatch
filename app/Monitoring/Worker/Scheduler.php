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
 * - OutcomeBuffer, through the ResultRecorder: writing outcomes;
 * - Heartbeat: writing the worker's heartbeat row;
 * - DatabaseDeadlineEvaluator: reading the state rows of the push and cron monitors due in one tick, in
 *   one batched query (reads only).
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
    private const DEADLINE_RETRY_SECONDS = 30;

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
     * Since when this worker has been able to see push and cron reports: its start, or the moment it saw
     * the database come back after an outage. Deadlines that passed earlier count from this moment.
     */
    private ?CarbonImmutable $availableSince = null;

    private bool $deadlineReadsFailing = false;

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
        private readonly float $shutdownGraceSeconds = 10.0,
    ) {
        $this->slots = new LocalSemaphore(max(1, $concurrency));
        $this->heap = new SplMinHeap;
        $this->wake = new DeferredCancellation;
        $this->abort = new DeferredCancellation;
    }

    /**
     * Runs until $stop is cancelled, then waits for checks in flight, flushes and returns.
     */
    public function run(Cancellation $stop): void
    {
        if (! $this->load($stop)) {
            return;
        }
        $this->availableSince = $this->clock->now();

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
        $this->buffer->drain();
        $this->heartbeat->beat(count($this->specs), $this->buffer->count());
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
                    $deadlines[] = $id;
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
        while (true) {
            $this->heartbeat->beat(count($this->specs), $this->buffer->count());
            try {
                $this->clock->sleep($this->heartbeatSeconds, $stop);
            } catch (CancelledException) {
                return;
            }
        }
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
     * @param  list<int>  $ids
     */
    private function launchDeadlines(array $ids): void
    {
        /** @var array<int, array{0: MonitorSpec, 1: int}> $batch */
        $batch = [];
        foreach ($ids as $id) {
            $spec = $this->specs[$id] ?? null;
            $generation = $this->generations[$id] ?? null;
            if ($spec !== null && $generation !== null) {
                $this->busy[$id] = true;
                $batch[$id] = [$spec, $generation];
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
     * @param  array<int, array{0: MonitorSpec, 1: int}>  $batch
     */
    private function evaluateDeadlines(array $batch): void
    {
        $now = $this->clock->now();
        $nowAt = $this->timestampOf($now);
        $specs = [];
        foreach ($batch as [$spec]) {
            $specs[] = $spec;
        }

        // After failed reads, a successful one is the moment the database came back.
        $since = $this->deadlineReadsFailing ? $now : $this->availableSince();
        try {
            $results = $this->deadlines->evaluateMany($specs, $now, $since);
        } catch (Throwable $e) {
            $this->deadlineReadsFailing = true;
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
        if ($this->deadlineReadsFailing) {
            $this->deadlineReadsFailing = false;
            $this->availableSince = $now;
        }

        foreach ($batch as $id => [$spec, $generation]) {
            [$outcome, $next] = $results[$id] ?? [null, $now->addSeconds($spec->intervalSeconds)];
            $nextAt = max($nowAt + 1, $this->timestampOf($next));

            if ($outcome === null || ! $this->isCurrent($id, $generation)) {
                $this->release($id, $generation, $nextAt);

                continue;
            }

            $this->buffer->push($spec, $outcome, $now, null, fn () => $this->release($id, $generation, $nextAt));
        }
    }

    private function availableSince(): ?CarbonImmutable
    {
        $recovered = $this->buffer->recoveredAt();
        if ($recovered === null || ($this->availableSince !== null && $recovered->lt($this->availableSince))) {
            return $this->availableSince;
        }

        return $recovered;
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
     * Waits up to the grace period for checks in flight, then cancels the rest.
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
