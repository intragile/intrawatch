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
 * Only database errors (QueryException, PDOException) count as failures. A failure starts at the earlier of
 * the moment it happened and the earliest due moment of the deadlines whose read or write failed, so a
 * deadline whose own evaluation is the first thing to fail falls inside it. It ends once both a read and a
 * write have succeeded after the last failure, at the later of the two.
 *
 * Only a failure that lasted at least MIN_SECONDS is an outage; the listeners learn about it when it ends.
 * A single deadlock, busy or lock-timeout error, or any burst shorter than that, is not one: reports kept
 * arriving meanwhile. All moments are kept at whole seconds, the precision at which deadlines are stored.
 */
final class OutageTracker
{
    public const MIN_SECONDS = 30;

    private ?CarbonImmutable $failingSince = null;

    private ?CarbonImmutable $readAt = null;

    private ?CarbonImmutable $writtenAt = null;

    /** @var list<Closure(CarbonImmutable, CarbonImmutable): void> */
    private array $listeners = [];

    public static function isDatabaseError(Throwable $e): bool
    {
        return $e instanceof QueryException || $e instanceof PDOException;
    }

    /**
     * @param  Closure(CarbonImmutable, CarbonImmutable): void  $listener  Called with the start and the end of each outage.
     */
    public function onOutageEnded(Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * A database call failed at $at.
     *
     * @param  ?CarbonImmutable  $earliestDue  The earliest due moment of the deadlines whose read or write failed.
     */
    public function failed(CarbonImmutable $at, ?CarbonImmutable $earliestDue = null): void
    {
        $since = ($earliestDue !== null && $earliestDue->lt($at) ? $earliestDue : $at)->startOfSecond();
        if ($this->failingSince === null || $since->lt($this->failingSince)) {
            $this->failingSince = $since;
        }
        $this->readAt = null;
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

    private function settle(): void
    {
        if ($this->failingSince === null || $this->readAt === null || $this->writtenAt === null) {
            return;
        }

        $from = $this->failingSince;
        $to = max($this->readAt, $this->writtenAt);
        $this->failingSince = null;
        $this->readAt = null;
        $this->writtenAt = null;

        if ($to->getTimestamp() - $from->getTimestamp() >= self::MIN_SECONDS) {
            foreach ($this->listeners as $listener) {
                $listener($from, $to);
            }
        }
    }
}
