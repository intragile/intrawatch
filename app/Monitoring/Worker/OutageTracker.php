<?php

namespace App\Monitoring\Worker;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * Tells database outages apart from transient errors.
 *
 * Only database errors (QueryException, PDOException) of two kinds of calls count: deadline reads and outcome
 * writes. A failure starts at the earlier of the moment it happened and the earliest due moment of the
 * deadlines whose read or write failed, so a deadline whose own evaluation is the first thing to fail falls
 * inside it. A failed read is cleared by a later successful read and a failed write by a later successful
 * write (a written worker heartbeat counts as one); the failure ends once both kinds have succeeded since it
 * started and each kind since its own last failure, at the later of the two. Short failures of alternating
 * kinds with successes in between therefore stay separate instead of chaining into one long failure.
 *
 * Only a failure that lasted at least MIN_SECONDS is an outage; the listeners learn about it when it ends.
 * A single deadlock, busy or lock-timeout error, or any burst shorter than that, is not one: reports kept
 * arriving meanwhile.
 *
 * Deadlines due since the start of a failure are held back (see holdingSince()) and re-anchored when it ends.
 * How long depends on what fails:
 * - Full failure: the last deadline read and the last write (outcome or worker heartbeat) both failed. Reports
 *   may be lost and nothing can be recorded, so there is no cap: the whole span is held back and re-anchored.
 * - Partial failure: one kind still works. Only MAX_HOLD_SECONDS spent in this state are allowed per failure,
 *   so a call that keeps failing never hides a dead monitor for longer. When they are used up, holding stops,
 *   and the window re-anchored for this failure ends at the moment the cap was reached.
 * - A failure that switches between the two counts only its partial time against the cap. A full failure
 *   that starts after the cap was used up opens a new window from that moment, again held back and
 *   re-anchored as a whole, with a fresh cap for the partial time after it.
 * A failed heartbeat only marks writes as failing for this distinction; it never starts a failure.
 *
 * All moments are kept at whole seconds, the precision at which deadlines are stored.
 */
final class OutageTracker
{
    public const MIN_SECONDS = 30;

    public const MAX_HOLD_SECONDS = 300;

    private ?CarbonImmutable $failingSince = null;

    private ?CarbonImmutable $readAt = null;

    private ?CarbonImmutable $writtenAt = null;

    /** Whether the last deadline read failed. */
    private bool $readFailing = false;

    /** Whether the last write, an outcome write or a heartbeat, failed. */
    private bool $writeFailing = false;

    /** The start of the whole failure, which decides whether it was an outage. */
    private ?CarbonImmutable $firstFailure = null;

    /** Seconds spent in a partial failure since failingSince, up to accountedAt. */
    private int $partialSeconds = 0;

    private int $accountedAt = 0;

    /** When the partial-failure cap of the current window was reached, if it was. */
    private ?CarbonImmutable $capReachedAt = null;

    /** @var list<array{0: CarbonImmutable, 1: CarbonImmutable}> Earlier windows of this failure, closed at their cap. */
    private array $windows = [];

    /** @var list<Closure(CarbonImmutable, CarbonImmutable, CarbonImmutable): void> */
    private array $listeners = [];

    public static function isDatabaseError(Throwable $e): bool
    {
        return $e instanceof QueryException || $e instanceof PDOException;
    }

    /**
     * Each listener is called, once per window to re-anchor, with the start and the end of the window and the
     * end of the outage. A window ends at the end of the outage, or where its partial-failure cap was reached.
     *
     * @param  Closure(CarbonImmutable, CarbonImmutable, CarbonImmutable): void  $listener
     */
    public function onOutageEnded(Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * A deadline read failed at $at.
     *
     * @param  ?CarbonImmutable  $earliestDue  The earliest due moment of the deadlines whose read failed.
     */
    public function readFailed(CarbonImmutable $at, ?CarbonImmutable $earliestDue = null): void
    {
        $this->account($at);
        $this->start($at, $earliestDue);
        $this->readAt = null;
        $this->readFailing = true;
        $this->reopen($at);
    }

    /**
     * An outcome write failed at $at.
     *
     * @param  ?CarbonImmutable  $earliestDue  The earliest deadline among the outcomes whose write failed.
     */
    public function writeFailed(CarbonImmutable $at, ?CarbonImmutable $earliestDue = null): void
    {
        $this->account($at);
        $this->start($at, $earliestDue);
        $this->writtenAt = null;
        $this->writeFailing = true;
        $this->reopen($at);
    }

    /**
     * Writing the worker heartbeat failed at $at. It tells a full failure from a partial one, but neither
     * starts a failure nor keeps one from ending.
     */
    public function heartbeatFailed(CarbonImmutable $at): void
    {
        $this->account($at);
        $this->writeFailing = true;
        $this->reopen($at);
    }

    public function readSucceeded(CarbonImmutable $at): void
    {
        $this->account($at);
        $this->readFailing = false;
        if ($this->failingSince !== null) {
            $this->readAt = $at->startOfSecond();
            $this->settle();
        }
    }

    public function writeSucceeded(CarbonImmutable $at): void
    {
        $this->account($at);
        $this->writeFailing = false;
        if ($this->failingSince !== null) {
            $this->writtenAt = $at->startOfSecond();
            $this->settle();
        }
    }

    /**
     * The start of the failure going on, or null while the database works.
     */
    public function failingSince(): ?CarbonImmutable
    {
        return $this->failingSince;
    }

    /**
     * The start of the current window while deadlines due since then are held back at $now: null while the
     * database works, and once the window has spent MAX_HOLD_SECONDS in a partial failure.
     */
    public function holdingSince(CarbonImmutable $now): ?CarbonImmutable
    {
        if ($this->failingSince === null || $this->capReachedAt !== null) {
            return null;
        }
        $partial = $this->partialSeconds + ($this->full() ? 0 : max(0, $now->getTimestamp() - $this->accountedAt));

        return $partial < self::MAX_HOLD_SECONDS ? $this->failingSince : null;
    }

    private function full(): bool
    {
        return $this->readFailing && $this->writeFailing;
    }

    /**
     * Adds the time since the last report to the partial-failure time if the failure was partial meanwhile,
     * and records when the cap was reached. Must run before a report changes the state.
     */
    private function account(CarbonImmutable $at): void
    {
        $now = $at->getTimestamp();
        if ($this->failingSince !== null && $this->capReachedAt === null && ! $this->full() && $now > $this->accountedAt) {
            $partial = $this->partialSeconds + $now - $this->accountedAt;
            if ($partial >= self::MAX_HOLD_SECONDS) {
                $this->capReachedAt = CarbonImmutable::createFromTimestamp(
                    $this->accountedAt + self::MAX_HOLD_SECONDS - $this->partialSeconds, 'UTC');
                $partial = self::MAX_HOLD_SECONDS;
            }
            $this->partialSeconds = $partial;
        }
        $this->accountedAt = max($this->accountedAt, $now);
    }

    private function start(CarbonImmutable $at, ?CarbonImmutable $earliestDue): void
    {
        $since = ($earliestDue !== null && $earliestDue->lt($at) ? $earliestDue : $at)->startOfSecond();
        if ($this->failingSince === null) {
            $this->failingSince = $since;
            $this->firstFailure = $since;
            $this->readAt = null;
            $this->writtenAt = null;
            $this->partialSeconds = 0;
            $this->capReachedAt = null;
            $this->windows = [];
            $this->accountedAt = $at->getTimestamp();
        } elseif ($since->lt($this->failingSince) && $this->capReachedAt === null) {
            $this->failingSince = $since;
            if ($this->firstFailure === null || $since->lt($this->firstFailure)) {
                $this->firstFailure = $since;
            }
        }
    }

    /**
     * A full failure after the cap of the current window was used up opens a new window at $at.
     */
    private function reopen(CarbonImmutable $at): void
    {
        if ($this->failingSince === null || $this->capReachedAt === null || ! $this->full()) {
            return;
        }

        $this->windows[] = [$this->failingSince, $this->capReachedAt];
        $this->failingSince = $at->startOfSecond();
        $this->capReachedAt = null;
        $this->partialSeconds = 0;
        $this->accountedAt = max($this->accountedAt, $at->getTimestamp());
    }

    private function settle(): void
    {
        if ($this->failingSince === null || $this->readAt === null || $this->writtenAt === null) {
            return;
        }

        $end = max($this->readAt, $this->writtenAt);
        $windows = [...$this->windows, [$this->failingSince, min($end, $this->capReachedAt ?? $end)]];
        $first = $this->firstFailure ?? $this->failingSince;
        $this->failingSince = null;
        $this->firstFailure = null;
        $this->readAt = null;
        $this->writtenAt = null;
        $this->capReachedAt = null;
        $this->windows = [];

        if ($end->getTimestamp() - $first->getTimestamp() < self::MIN_SECONDS) {
            return;
        }
        foreach ($windows as [$from, $to]) {
            if ($to->gt($from)) {
                foreach ($this->listeners as $listener) {
                    $listener($from, $to, $end);
                }
            }
        }
    }
}
