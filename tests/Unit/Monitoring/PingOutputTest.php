<?php

namespace Tests\Unit\Monitoring;

use App\Monitoring\Checks\PingOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PingOutputTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int, 2: ?float}>
     */
    public static function summaries(): array
    {
        return [
            'iputils full' => ["--- 10.0.0.1 ping statistics ---\n5 packets transmitted, 5 received, 0% packet loss, time 4005ms\nrtt min/avg/max/mdev = 0.040/0.052/0.071/0.011 ms\n", 5, 0.052],
            'iputils partial loss' => ["3 packets transmitted, 2 received, 33.3333% packet loss, time 2003ms\nrtt min/avg/max/mdev = 10.1/12.5/14.9/2.4 ms\n", 2, 12.5],
            'iputils total loss' => ["4 packets transmitted, 0 received, 100% packet loss, time 3070ms\n\n", 0, null],
            'macos' => ["--- 127.0.0.1 ping statistics ---\n2 packets transmitted, 2 packets received, 0.0% packet loss\nround-trip min/avg/max/stddev = 0.044/0.051/0.058/0.007 ms\n", 2, 0.051],
            'macos total loss' => ["1 packets transmitted, 0 packets received, 100.0% packet loss\n", 0, null],
            'busybox' => ["--- 10.0.0.1 ping statistics ---\n4 packets transmitted, 3 packets received, 25% packet loss\nround-trip min/avg/max = 0.9/1.2/1.7 ms\n", 3, 1.2],
        ];
    }

    #[DataProvider('summaries')]
    public function test_summary_formats(string $text, int $received, ?float $average): void
    {
        $parsed = PingOutput::fromSummary($text);

        $this->assertNotNull($parsed);
        $this->assertSame($received, $parsed->received);
        $this->assertEqualsWithDelta($average, $parsed->averageMs, 0.0001);
    }

    public function test_unrecognised_output_has_no_summary(): void
    {
        $this->assertNull(PingOutput::fromSummary("ping: unknown host\n"));
    }

    public function test_replies_are_averaged_when_the_summary_is_missing(): void
    {
        $parsed = PingOutput::fromReplies("PING 10.0.0.1\n64 bytes from 10.0.0.1: icmp_seq=1 ttl=64 time=2.0 ms\n64 bytes from 10.0.0.1: icmp_seq=2 ttl=64 time=4.0 ms\n");

        $this->assertNotNull($parsed);
        $this->assertSame(2, $parsed->received);
        $this->assertEqualsWithDelta(3.0, $parsed->averageMs, 0.0001);
        $this->assertNull(PingOutput::fromReplies("PING 10.0.0.1\n"));
    }
}
