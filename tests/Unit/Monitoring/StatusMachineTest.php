<?php

namespace Tests\Unit\Monitoring;

use App\Enums\MonitorStatus;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\StateSnapshot;
use App\Monitoring\Engine\StatusMachine;
use RuntimeException;
use Tests\Support\MonitorSpecFactory;
use Tests\TestCase;

class StatusMachineTest extends TestCase
{
    public function test_single_failure_with_retries_goes_pending_then_recovers(): void
    {
        $spec = MonitorSpecFactory::http(retries: 1);
        $t1 = (new StatusMachine)->apply($spec, StateSnapshot::of(MonitorStatus::Up, 0), CheckOutcome::down('500'), false);
        $this->assertSame(MonitorStatus::Pending, $t1->effective);
        $this->assertSame(1, $t1->consecutiveFailures);
        $this->assertTrue($t1->changed);
        $this->assertSame($spec->retryIntervalSeconds, $t1->nextDelaySeconds);

        $t2 = (new StatusMachine)->apply($spec, StateSnapshot::of(MonitorStatus::Pending, 1), CheckOutcome::up(120), false);
        $this->assertSame(MonitorStatus::Up, $t2->effective);
        $this->assertSame(0, $t2->consecutiveFailures);
        $this->assertTrue($t2->changed);
        $this->assertSame($spec->intervalSeconds, $t2->nextDelaySeconds);
    }

    public function test_second_failure_goes_down_and_final_skips_retries(): void
    {
        $spec = MonitorSpecFactory::http(retries: 1);
        $machine = new StatusMachine;

        $t = $machine->apply($spec, StateSnapshot::of(MonitorStatus::Pending, 1), CheckOutcome::down('500'), false);
        $this->assertSame(MonitorStatus::Down, $t->effective);
        $this->assertSame(2, $t->consecutiveFailures);
        $this->assertSame($spec->intervalSeconds, $t->nextDelaySeconds);

        $final = $machine->apply($spec, StateSnapshot::of(MonitorStatus::Up, 0), CheckOutcome::down('certificate expired')->final(), false);
        $this->assertSame(MonitorStatus::Down, $final->effective);
        $this->assertSame(1, $final->consecutiveFailures);

        $stillDown = $machine->apply(MonitorSpecFactory::http(retries: 5), StateSnapshot::of(MonitorStatus::Down, 0), CheckOutcome::down('500'), false);
        $this->assertSame(MonitorStatus::Down, $stillDown->effective);
        $this->assertFalse($stillDown->changed);

        $noRetries = $machine->apply(MonitorSpecFactory::http(retries: 0), StateSnapshot::of(MonitorStatus::Up, 0), CheckOutcome::down('500'), false);
        $this->assertSame(MonitorStatus::Down, $noRetries->effective);
    }

    public function test_inverted_and_degraded_threshold(): void
    {
        $machine = new StatusMachine;

        $inverted = MonitorSpecFactory::http(inverted: true);
        $this->assertSame(MonitorStatus::Up, $machine->apply($inverted, StateSnapshot::of(MonitorStatus::Up, 0), CheckOutcome::down('refused'), false)->effective);
        $flipped = $machine->apply($inverted, StateSnapshot::of(MonitorStatus::Up, 0), CheckOutcome::up(10)->final(), false);
        $this->assertSame(MonitorStatus::Down, $flipped->effective);
        $this->assertSame(MonitorStatus::Pending, $machine->apply($inverted, StateSnapshot::of(MonitorStatus::Up, 0), CheckOutcome::degraded('slow'), false)->effective);

        // Types without inversion support ignore the flag.
        $tls = MonitorSpecFactory::tls(inverted: true, retries: 0);
        $this->assertSame(MonitorStatus::Down, $machine->apply($tls, StateSnapshot::of(MonitorStatus::Up, 0), CheckOutcome::down('expired'), false)->effective);

        $slow = MonitorSpecFactory::http(degradedThresholdMs: 500);
        $t = $machine->apply($slow, StateSnapshot::of(MonitorStatus::Up, 0), CheckOutcome::up(900), false);
        $this->assertSame(MonitorStatus::Degraded, $t->effective);
        $this->assertSame('Slow response: 900 ms', $t->message);
        $this->assertSame(0, $t->consecutiveFailures);
        $this->assertSame(MonitorStatus::Up, $machine->apply($slow, StateSnapshot::of(MonitorStatus::Up, 0), CheckOutcome::up(500), false)->effective);
    }

    public function test_maintenance_overrides_and_keeps_counter(): void
    {
        $spec = MonitorSpecFactory::http(retries: 1);
        $t = (new StatusMachine)->apply($spec, StateSnapshot::of(MonitorStatus::Pending, 1), CheckOutcome::down('500'), true);

        $this->assertSame(MonitorStatus::Maintenance, $t->effective);
        $this->assertSame(1, $t->consecutiveFailures);
        $this->assertTrue($t->changed);
        $this->assertSame($spec->intervalSeconds, $t->nextDelaySeconds);
    }

    public function test_outcome_truncates_message_and_hides_exception_messages(): void
    {
        $this->assertSame(500, mb_strlen(CheckOutcome::down(str_repeat('é', 600))->message));

        $outcome = CheckOutcome::fromThrowable(new RuntimeException('https://user:pass@example.test failed'));
        $this->assertSame(MonitorStatus::Down, $outcome->status);
        $this->assertSame('RuntimeException', $outcome->message);
    }
}
