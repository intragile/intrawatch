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
 * Deadlines are held back for at most MAX_HOLD_SECONDS after the start of a failure (see holdingSince()), and
 * the window reported to the listeners never extends past that cap: a dead monitor is never hidden longer
 * because some database call keeps failing. All moments are kept at whole seconds, the precision at which
 * deadlines are stored.
 */
final class OutageTracker
{
    public const MIN_SECONDS = 30;

    public const MAX_HOLD_SECONDS = 300;

    private ?CarbonImmutable $failingSince = null;

    private ?CarbonImmutable $readAt = null;

    private ?CarbonImmutable $writtenAt = null;

    /** @var list<Closure(CarbonImmutable, CarbonImmutable, CarbonImmutable): void> */
    private array $listeners = [];

    public static function isDatabaseError(Throwable $e): bool
    {
        return $e instanceof QueryException || $e instanceof PDOException;
    }

    /**
     * Each listener is called with the start of an outage, the end of its window to re-anchor (at most
     * MAX_HOLD_SECONDS after the start) and the end of the outage.
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
        $this->start($at, $earliestDue);
        $this->readAt = null;
    }

    /**
     * An outcome write failed at $at.
     *
     * @param  ?CarbonImmutable  $earliestDue  The earliest deadline among the outcomes whose write failed.
     */
    public function writeFailed(CarbonImmutable $at, ?CarbonImmutable $earliestDue = null): void
    {
        $this->start($at, $earliestDue);
        $this->writtenAt = null;
    }

    public function readSucceeded(CarbonImmutable $at): void
    {
        if ($this->failingSince !== null) {
            $this->readAt = $at->startOfSecond();
            $this->settle();
        }
    }

    public function writeSucceeded(CarbonImmutable $at): void
    {
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
     * The start of the failure going on while deadlines due since then are still held back at $now: null while
     * the database works, and from MAX_HOLD_SECONDS after the start of the failure on.
     */
    public function holdingSince(CarbonImmutable $now): ?CarbonImmutable
    {
        if ($this->failingSince === null || $now->getTimestamp() >= $this->failingSince->getTimestamp() + self::MAX_HOLD_SECONDS) {
            return null;
        }

        return $this->failingSince;
    }

    private function start(CarbonImmutable $at, ?CarbonImmutable $earliestDue): void
    {
        $since = ($earliestDue !== null && $earliestDue->lt($at) ? $earliestDue : $at)->startOfSecond();
        if ($this->failingSince === null) {
            $this->failingSince = $since;
            $this->readAt = null;
            $this->writtenAt = null;
        } elseif ($since->lt($this->failingSince)) {
            $this->failingSince = $since;
        }
    }

    private function settle(): void
    {
        if ($this->failingSince === null || $this->readAt === null || $this->writtenAt === null) {
            return;
        }

        $from = $this->failingSince;
        $end = max($this->readAt, $this->writtenAt);
        $this->failingSince = null;
        $this->readAt = null;
        $this->writtenAt = null;

        if ($end->getTimestamp() - $from->getTimestamp() >= self::MIN_SECONDS) {
            $to = min($end, $from->addSeconds(self::MAX_HOLD_SECONDS));
            foreach ($this->listeners as $listener) {
                $listener($from, $to, $end);
            }
        }
    }
}
