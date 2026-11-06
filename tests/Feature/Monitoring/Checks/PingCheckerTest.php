<?php

namespace Tests\Feature\Monitoring\Checks;

use Amp\NullCancellation;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Monitoring\Checks\PingChecker;
use App\Monitoring\Net\NetworkPolicy;
use Tests\Support\MonitorSpecFactory;
use Tests\Support\StubDnsResolver;
use Tests\TestCase;

class PingCheckerTest extends TestCase
{
    private function pingAvailable(): bool
    {
        foreach (['/sbin/ping', '/bin/ping', '/usr/bin/ping', '/usr/sbin/ping'] as $path) {
            if (is_executable($path)) {
                return true;
            }
        }

        return false;
    }

    public function test_ping_localhost(): void
    {
        if (! $this->pingAvailable()) {
            $this->markTestSkipped('The ping binary is not installed.');
        }

        $spec = MonitorSpecFactory::of(MonitorType::Ping, ['host' => '127.0.0.1', 'count' => 2]);
        $outcome = (new PingChecker(new StubDnsResolver, new NetworkPolicy))->check($spec, new NullCancellation);

        if ($outcome->status === MonitorStatus::Down && $outcome->message !== '2/2 packets lost') {
            $this->markTestSkipped('Ping is not permitted here: '.$outcome->message);
        }

        $this->assertSame(MonitorStatus::Up, $outcome->status, $outcome->message);
        $this->assertGreaterThanOrEqual(0, $outcome->latencyMs);
    }

    public function test_ping_resolves_through_the_policy_first(): void
    {
        $spec = MonitorSpecFactory::of(MonitorType::Ping, ['host' => 'evil.test', 'count' => 1]);
        $checker = new PingChecker(new StubDnsResolver(['evil.test' => ['169.254.169.254']]), new NetworkPolicy(['169.254.169.254/32']));

        $outcome = $checker->check($spec, new NullCancellation);

        $this->assertSame(MonitorStatus::Down, $outcome->status);
        $this->assertSame('Blocked target', $outcome->message);
    }

    public function test_ping_reports_loss_when_nobody_answers(): void
    {
        if (! $this->pingAvailable()) {
            $this->markTestSkipped('The ping binary is not installed.');
        }

        $spec = MonitorSpecFactory::of(MonitorType::Ping, ['host' => '192.0.2.1', 'count' => 1], timeoutSeconds: 1);

        $outcome = (new PingChecker(new StubDnsResolver, new NetworkPolicy))->check($spec, new NullCancellation);

        $this->assertSame(MonitorStatus::Down, $outcome->status);
    }
}
