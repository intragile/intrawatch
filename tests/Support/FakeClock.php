<?php

namespace Tests\Support;

use Amp\Cancellation;
use Amp\CancelledException;
use App\Monitoring\Worker\Clock;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Revolt\EventLoop;
use Revolt\EventLoop\CallbackType;
use Revolt\EventLoop\Suspension;

/**
 * A virtual clock for driving event-loop code deterministically.
 *
 * Fibers that sleep on this clock are parked until advance() moves the virtual time past their wake-up
 * time; nothing waits in real time. The clock also pins Carbon's test time to the virtual time, so rows
 * written by the application during a test carry the same timestamps the worker sees.
 */
final class FakeClock implements Clock
{
    private readonly CarbonImmutable $start;

    private float $offset = 0.0;

    private int $sequence = 0;

    /** @var array<int, array{0: float, 1: Suspension<null>}> */
    private array $sleepers = [];

    public function __construct(?CarbonImmutable $start = null)
    {
        $this->start = ($start ?? CarbonImmutable::now())->startOfSecond();
        $this->pin();
    }

    public function now(): CarbonImmutable
    {
        return $this->start->addMicroseconds((int) round($this->offset * 1_000_000));
    }

    public function sleep(float $seconds, ?Cancellation $cancellation = null): void
    {
        $cancellation?->throwIfRequested();

        $id = ++$this->sequence;
        /** @var Suspension<null> $suspension */
        $suspension = EventLoop::getSuspension();
        $this->sleepers[$id] = [$this->offset + max(0.0, $seconds), $suspension];

        $subscription = $cancellation?->subscribe(function (CancelledException $e) use ($id, $suspension): void {
            if (isset($this->sleepers[$id])) {
                unset($this->sleepers[$id]);
                $suspension->throw($e);
            }
        });

        try {
            $suspension->suspend();
        } finally {
            if ($subscription !== null) {
                $cancellation->unsubscribe($subscription);
            }
        }
    }

    /**
     * Moves the virtual time forward, waking every sleeper on the way in wake-up order and letting the
     * event loop settle after each one.
     */
    public function advance(float $seconds): void
    {
        $target = $this->offset + $seconds;

        while (true) {
            $this->settle();

            $nextId = null;
            $nextAt = INF;
            foreach ($this->sleepers as $id => [$at]) {
                if ($at < $nextAt) {
                    $nextId = $id;
                    $nextAt = $at;
                }
            }
            if ($nextId === null || $nextAt > $target) {
                break;
            }

            $suspension = $this->sleepers[$nextId][1];
            unset($this->sleepers[$nextId]);
            $this->offset = max($this->offset, $nextAt);
            $this->pin();
            $suspension->resume();
        }

        $this->offset = $target;
        $this->pin();
        $this->settle();
    }

    /**
     * Yields to the event loop until no deferred callbacks are left, so every fiber has run up to its next
     * sleep or wait.
     */
    public function settle(): void
    {
        $quiet = 0;
        for ($round = 0; $round < 10_000 && $quiet < 3; $round++) {
            $suspension = EventLoop::getSuspension();
            EventLoop::defer(static fn () => $suspension->resume());
            $suspension->suspend();

            $quiet = $this->hasDeferred() ? 0 : $quiet + 1;
        }
    }

    private function hasDeferred(): bool
    {
        foreach (EventLoop::getIdentifiers() as $id) {
            if (EventLoop::getType($id) === CallbackType::Defer && EventLoop::isEnabled($id)) {
                return true;
            }
        }

        return false;
    }

    private function pin(): void
    {
        $now = $this->now();
        CarbonImmutable::setTestNow($now);
        Carbon::setTestNow($now);
    }
}
