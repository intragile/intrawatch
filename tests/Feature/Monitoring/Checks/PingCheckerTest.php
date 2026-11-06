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

        if ($outcome->status === MonitorStatus::Down && $outcome->message === '2/2 packets lost') {
            $this->markTestSkipped('ICMP is not permitted in this environment.');
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

    private function fakePing(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fakeping');
        file_put_contents($path, "#!/bin/sh\n{$body}\n");
        chmod($path, 0755);
        $this->beforeApplicationDestroyed(static fn () => @unlink($path));

        return $path;
    }

    public function test_partial_replies_at_the_deadline_count_as_up(): void
    {
        $binary = $this->fakePing("echo '64 bytes from 10.0.0.1: icmp_seq=1 ttl=64 time=4.0 ms'\nexec sleep 30");
        $spec = MonitorSpecFactory::of(MonitorType::Ping, ['host' => '10.0.0.1', 'count' => 3], timeoutSeconds: 1);

        $started = microtime(true);
        $outcome = (new PingChecker(new StubDnsResolver, new NetworkPolicy, $binary))->check($spec, new NullCancellation);

        $this->assertSame(MonitorStatus::Up, $outcome->status, $outcome->message);
        $this->assertSame(4, $outcome->latencyMs);
        $this->assertSame(2, $outcome->facts['packets_lost']);
        $this->assertLessThan(2.6, microtime(true) - $started);
    }

    public function test_silence_until_the_deadline_is_a_timeout(): void
    {
        $binary = $this->fakePing('exec sleep 30');
        $spec = MonitorSpecFactory::of(MonitorType::Ping, ['host' => '10.0.0.1', 'count' => 1], timeoutSeconds: 1);

        $outcome = (new PingChecker(new StubDnsResolver, new NetworkPolicy, $binary))->check($spec, new NullCancellation);

        $this->assertSame(MonitorStatus::Down, $outcome->status);
        $this->assertSame('Timed out after 1 s', $outcome->message);
    }

    public function test_summary_is_interpreted_from_the_binary_output(): void
    {
        $binary = $this->fakePing("echo '3 packets transmitted, 2 received, 33% packet loss, time 2003ms'\necho 'rtt min/avg/max/mdev = 0.1/0.6/1.0/0.2 ms'");
        $spec = MonitorSpecFactory::of(MonitorType::Ping, ['host' => '10.0.0.1', 'count' => 3]);

        $outcome = (new PingChecker(new StubDnsResolver, new NetworkPolicy, $binary))->check($spec, new NullCancellation);

        $this->assertSame(MonitorStatus::Up, $outcome->status);
        $this->assertSame(1, $outcome->latencyMs);
        $this->assertSame(1, $outcome->facts['packets_lost']);
    }
}
