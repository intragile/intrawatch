<?php

namespace App\Monitoring\Engine;

use Carbon\CarbonImmutable;

/**
 * The missed deadline a push or cron outcome reports, carried along so the recorder can tell whether the
 * monitor reported in after it was evaluated, and whether this miss was recorded already.
 */
final readonly class Deadline
{
    /**
     * The moment this outcome fell due: the deadline itself, or for push miss k the moment that miss was due.
     * Database failures are measured against it, so an outcome that fell due a moment ago never makes a
     * short failure look as if it started at a deadline long past.
     */
    public CarbonImmutable $dueAt;

    /**
     * @param  CarbonImmutable  $at  The deadline that was missed.
     * @param  CarbonImmutable  $anchor  The moment the deadline counted from, as stored, when it was evaluated.
     * @param  ?CarbonImmutable  $runStartedAt  For an overrun: the start of the run that exceeded its maximum runtime.
     * @param  ?int  $miss  For a push heartbeat: which miss of this deadline it is. Miss k is due after
     *                      $at + k * retry interval (counted from a later origin once an outage moved the next
     *                      miss), so the number follows from time alone and repeating an evaluation never
     *                      counts another miss.
     * @param  ?CarbonImmutable  $dueAt  When this outcome fell due; $at when omitted.
     */
    public function __construct(
        public CarbonImmutable $at,
        public CarbonImmutable $anchor,
        public ?CarbonImmutable $runStartedAt = null,
        public ?int $miss = null,
        ?CarbonImmutable $dueAt = null,
    ) {
        $this->dueAt = $dueAt ?? $at;
    }
}
