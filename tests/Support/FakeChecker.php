<?php

namespace Tests\Support;

use Amp\Cancellation;
use Amp\DeferredFuture;
use App\Monitoring\Checks\Checker;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Worker\Clock;
use Carbon\CarbonImmutable;

/**
 * A checker that answers up immediately, or holds a monitor's check until the test releases it.
 */
final class FakeChecker implements Checker
{
    /** @var list<array{0: int, 1: CarbonImmutable}> */
    public array $calls = [];

    /** @var array<int, DeferredFuture<mixed>> */
    private array $holds = [];

    public function __construct(private readonly Clock $clock) {}

    public function hold(int $monitorId): void
    {
        $this->holds[$monitorId] = new DeferredFuture;
    }

    public function release(int $monitorId, ?CheckOutcome $outcome = null): void
    {
        $deferred = $this->holds[$monitorId];
        unset($this->holds[$monitorId]);
        $deferred->complete($outcome ?? CheckOutcome::up(5));
    }

    public function check(MonitorSpec $spec, Cancellation $cancellation): CheckOutcome
    {
        $this->calls[] = [$spec->id, $this->clock->now()];

        if (isset($this->holds[$spec->id])) {
            $outcome = $this->holds[$spec->id]->getFuture()->await($cancellation);

            return $outcome instanceof CheckOutcome ? $outcome : CheckOutcome::up(5);
        }

        return CheckOutcome::up(5);
    }

    /**
     * @return list<CarbonImmutable>
     */
    public function callsFor(int $monitorId): array
    {
        $times = [];
        foreach ($this->calls as [$id, $at]) {
            if ($id === $monitorId) {
                $times[] = $at;
            }
        }

        return $times;
    }
}
