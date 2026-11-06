<?php

namespace App\Monitoring\Engine;

use Carbon\CarbonImmutable;

/**
 * When the evaluating worker could see push and cron reports. A deadline that passed while it could not
 * (before it started, or during an outage of the database) may have lost its reports on the way, so it
 * counts from the end of that blind spot instead.
 *
 * All moments are kept at whole seconds, the precision at which deadlines are stored and compared.
 */
final readonly class Availability
{
    public ?CarbonImmutable $startedAt;

    public ?CarbonImmutable $outageFrom;

    public ?CarbonImmutable $outageTo;

    /**
     * @param  ?CarbonImmutable  $startedAt  When the worker started evaluating.
     * @param  ?CarbonImmutable  $outageFrom  Start of the last database outage, as defined by OutageTracker.
     * @param  ?CarbonImmutable  $outageTo  When the database was seen again after it.
     */
    public function __construct(?CarbonImmutable $startedAt, ?CarbonImmutable $outageFrom = null, ?CarbonImmutable $outageTo = null)
    {
        $this->startedAt = $startedAt?->startOfSecond();
        $this->outageFrom = $outageFrom?->startOfSecond();
        $this->outageTo = $outageTo?->startOfSecond();
    }

    /**
     * The moment a deadline counts from instead, or null when the worker could see it pass.
     *
     * Only a deadline inside the outage window [from, to) or before the start moves; one that passed
     * before the outage began still counts.
     */
    public function shift(CarbonImmutable $deadline): ?CarbonImmutable
    {
        if ($this->outageFrom !== null && $this->outageTo !== null
            && $deadline->getTimestamp() >= $this->outageFrom->getTimestamp()
            && $deadline->getTimestamp() < $this->outageTo->getTimestamp()) {
            return $this->outageTo;
        }
        if ($this->startedAt !== null && $deadline->getTimestamp() < $this->startedAt->getTimestamp()) {
            return $this->startedAt;
        }

        return null;
    }
}
