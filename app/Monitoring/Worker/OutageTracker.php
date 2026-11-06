<?php

namespace App\Monitoring\Worker;

use Carbon\CarbonImmutable;

/**
 * Tells database outages apart from transient errors.
 *
 * An outage is a database failure that lasts at least MIN_SECONDS without interruption, from the first
 * failure to the first success after it. A single deadlock, busy or lock-timeout error, or any burst of
 * failures shorter than that, is not one: reports kept arriving meanwhile.
 */
final class OutageTracker
{
    public const MIN_SECONDS = 30;

    private ?CarbonImmutable $failingSince = null;

    /** @var array{0: CarbonImmutable, 1: CarbonImmutable}|null */
    private ?array $last = null;

    public function failed(CarbonImmutable $at): void
    {
        $this->failingSince ??= $at;
    }

    public function succeeded(CarbonImmutable $at): void
    {
        if ($this->failingSince === null) {
            return;
        }
        if ($this->lasted($this->failingSince, $at)) {
            $this->last = [$this->failingSince, $at];
        }
        $this->failingSince = null;
    }

    /**
     * The last outage that has ended, as [first failure, first success after it].
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function lastOutage(): ?array
    {
        return $this->last;
    }

    /**
     * The outage window as it stands at $now: a failure still going on counts up to $now once it has lasted
     * long enough, so the first successful read after it already sees the whole window.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function windowAt(CarbonImmutable $now): ?array
    {
        if ($this->failingSince !== null && $this->lasted($this->failingSince, $now)) {
            return [$this->failingSince, $now];
        }

        return $this->last;
    }

    private function lasted(CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return $to->getTimestamp() - $from->getTimestamp() >= self::MIN_SECONDS;
    }
}
