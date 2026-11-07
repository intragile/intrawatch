<?php

namespace Tests\Unit\Monitoring;

use App\Monitoring\Worker\OutageTracker;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class OutageTrackerTest extends TestCase
{
    public function test_a_failure_starts_when_the_earliest_affected_outcome_fell_due(): void
    {
        $tracker = new OutageTracker;
        $tracker->writeFailed(CarbonImmutable::parse('2026-11-06 12:00:00.400', 'UTC'), CarbonImmutable::parse('2026-11-06 11:59:20', 'UTC'));

        $this->assertSame('2026-11-06 11:59:20', $tracker->failingSince()?->format('Y-m-d H:i:s'));
    }

    public function test_a_failure_is_never_backdated_more_than_a_minute_before_it_was_observed(): void
    {
        $tracker = new OutageTracker;
        $tracker->writeFailed(CarbonImmutable::parse('2026-11-06 12:00:00', 'UTC'), CarbonImmutable::parse('2026-11-06 10:01:30', 'UTC'));
        $this->assertSame('2026-11-06 11:59:00', $tracker->failingSince()?->format('Y-m-d H:i:s'));

        $tracker->readFailed(CarbonImmutable::parse('2026-11-06 12:00:05', 'UTC'), CarbonImmutable::parse('2026-11-06 09:00:00', 'UTC'));
        $this->assertSame('2026-11-06 11:59:00', $tracker->failingSince()?->format('Y-m-d H:i:s'), 'a later failure cannot pull it back further');
    }
}
