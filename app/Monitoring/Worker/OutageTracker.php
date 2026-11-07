<?php

namespace App\Monitoring\Worker;

use App\Monitoring\Engine\DatabaseErrors;
use Carbon\CarbonImmutable;
use Closure;
use Throwable;

/**
 * Tells database outages apart from transient errors.
 *
 * Only database errors of two kinds of calls count: deadline reads and outcome writes. Heartbeat and poll
 * errors are logged by their callers and never tracked, so a query that keeps failing there cannot hold back
 * a down. A failure starts at the earlier of the moment it happened and the moment the earliest outcome
 * whose read or write failed fell due, so a deadline whose own evaluation is the first thing to fail falls
 * inside it. The start is never pulled back more than MAX_BACKDATE_SECONDS before the observed failure.
 *
 * A failure ends once a deadline read and a write (an outcome write or the worker heartbeat) have both
 * succeeded since it started, each since its own last failure; it ends at the success that completes the
 * pair. A shard without push or cron monitors never reads deadlines, so there a write alone ends it. Short
 * failures of alternating kinds with successes in between therefore stay separate instead of chaining into
 * one long failure.
 *
 * Only a failure that lasted at least MIN_SECONDS is an outage; the listeners learn about it when it ends.
 * A single deadlock, busy or lock-timeout error, or any burst shorter than that, is not one: reports kept
 * arriving meanwhile.
 *
 * Deadlines due since the start of a failure are held back (see holdingSince()) for as long as it lasts,
 * whether reads, writes or both fail, and the whole failure is re-anchored when it was an outage. While
 * writes fail nothing can be recorded and the reports of push and cron monitors are refused too, so a down
 * recorded for a deadline inside the failure would likely be false.
 *
 * All moments are kept at whole seconds, the precision at which deadlines are stored.
 */
final class OutageTracker
{
    public const MIN_SECONDS = 30;

    /** How far before the observed failure its start may be pulled back to the due moment of an outcome. */
    public const MAX_BACKDATE_SECONDS = 60;

    private ?CarbonImmutable $since = null;

    /** Whether a deadline read succeeded since the failure started and since the last failed read. */
    private bool $readOk = true;

    /** Whether a write succeeded since the failure started and since the last failed write. */
    private bool $writeOk = true;

    /** @var ?Closure(): bool */
    private ?Closure $readsExpected = null;

    /** @var list<Closure(CarbonImmutable, CarbonImmutable): void> */
    private array $listeners = [];

    public static function isDatabaseError(Throwable $e): bool
    {
        return DatabaseErrors::isDatabaseError($e);
    }

    /**
     * Each listener is called with the start and the end of every outage, once it has ended.
     *
     * @param  Closure(CarbonImmutable, CarbonImmutable): void  $listener
     */
    public function onOutageEnded(Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * Tells whether the shard reads deadlines at all; when it does not, a write alone ends a failure.
     *
     * @param  Closure(): bool  $whether
     */
    public function expectReadsWhen(Closure $whether): void
    {
        $this->readsExpected = $whether;
    }

    /**
     * A deadline read failed at $at.
     *
     * @param  ?CarbonImmutable  $earliestDue  When the earliest of the deadlines whose read failed fell due.
     */
    public function readFailed(CarbonImmutable $at, ?CarbonImmutable $earliestDue = null): void
    {
        $this->start($at, $earliestDue);
        $this->readOk = false;
    }

    /**
     * An outcome write failed at $at.
     *
     * @param  ?CarbonImmutable  $earliestDue  When the earliest of the outcomes whose write failed fell due.
     */
    public function writeFailed(CarbonImmutable $at, ?CarbonImmutable $earliestDue = null): void
    {
        $this->start($at, $earliestDue);
        $this->writeOk = false;
    }

    public function readSucceeded(CarbonImmutable $at): void
    {
        $this->readOk = true;
        $this->settle($at);
    }

    /**
     * An outcome write or the worker heartbeat succeeded at $at.
     */
    public function writeSucceeded(CarbonImmutable $at): void
    {
        $this->writeOk = true;
        $this->settle($at);
    }

    /**
     * The start of the failure going on, or null while the database works.
     */
    public function failingSince(): ?CarbonImmutable
    {
        return $this->since;
    }

    /**
     * The moment since which deadlines are held back: the start of the failure going on, or null while the
     * database works.
     */
    public function holdingSince(): ?CarbonImmutable
    {
        return $this->since;
    }

    private function start(CarbonImmutable $at, ?CarbonImmutable $earliestDue): void
    {
        $start = $at;
        if ($earliestDue !== null && $earliestDue->lt($at)) {
            $floor = $at->subSeconds(self::MAX_BACKDATE_SECONDS);
            $start = $earliestDue->lt($floor) ? $floor : $earliestDue;
        }
        $start = $start->startOfSecond();
        if ($this->since === null) {
            // Both kinds have to succeed again from here on.
            $this->since = $start;
            $this->readOk = false;
            $this->writeOk = false;
        } elseif ($start->lt($this->since)) {
            $this->since = $start;
        }
    }

    private function settle(CarbonImmutable $at): void
    {
        if ($this->since === null || ! $this->writeOk || (! $this->readOk && $this->readsExpected())) {
            return;
        }

        $from = $this->since;
        $to = $at->startOfSecond();
        $this->since = null;
        $this->readOk = true;
        $this->writeOk = true;

        if ($to->getTimestamp() - $from->getTimestamp() < self::MIN_SECONDS) {
            return;
        }
        foreach ($this->listeners as $listener) {
            $listener($from, $to);
        }
    }

    private function readsExpected(): bool
    {
        return $this->readsExpected === null || ($this->readsExpected)();
    }
}
