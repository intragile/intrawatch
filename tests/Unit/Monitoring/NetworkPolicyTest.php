<?php

namespace Tests\Unit\Monitoring;

use App\Monitoring\Net\NetworkPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class NetworkPolicyTest extends TestCase
{
    public function test_policy_parses_v4_v6_cidrs(): void
    {
        $policy = new NetworkPolicy(['10.0.0.0/8', '169.254.169.254/32', 'fd00:ec2::254/128', '2001:db8::/32', '192.168.1.130/25']);

        $this->assertFalse($policy->allows('10.1.2.3'));
        $this->assertFalse($policy->allows('169.254.169.254'));
        $this->assertTrue($policy->allows('169.254.169.253'));
        $this->assertTrue($policy->allows('11.0.0.1'));
        $this->assertFalse($policy->allows('fd00:ec2::254'));
        $this->assertTrue($policy->allows('fd00:ec2::255'));
        $this->assertFalse($policy->allows('2001:db8:ffff::1'));
        $this->assertTrue($policy->allows('2001:db9::1'));
        $this->assertFalse($policy->allows('192.168.1.200'));
        $this->assertTrue($policy->allows('192.168.1.1'));
        $this->assertTrue($policy->allows('8.8.8.8'));
    }

    public function test_ipv4_mapped_ipv6_cannot_bypass_an_ipv4_rule(): void
    {
        $policy = new NetworkPolicy(['169.254.169.254/32']);

        $this->assertFalse($policy->allows('::ffff:169.254.169.254'));
        $this->assertFalse($policy->allows('::ffff:a9fe:a9fe'));
    }

    public function test_non_ip_input_is_denied(): void
    {
        $this->assertFalse((new NetworkPolicy([]))->allows('example.test'));
    }

    public function test_invalid_cidr_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new NetworkPolicy(['10.0.0.0/33']);
    }

    public function test_the_registries_policy_covers_special_purpose_ranges(): void
    {
        $policy = new NetworkPolicy(NetworkPolicy::NON_PUBLIC_NETWORKS);

        foreach (['192.0.0.8', '198.18.0.1', '198.19.255.254', '224.0.0.1', '239.255.255.250', '240.0.0.1', '255.255.255.255', '64:ff9b::a00:1', '2002:a00:1::1', 'ff02::1'] as $ip) {
            $this->assertFalse($policy->allows($ip), "{$ip} is not a public registry address");
        }
        foreach (['8.8.8.8', '198.20.0.1', '223.255.255.254', '2001:4860:4860::8888'] as $ip) {
            $this->assertTrue($policy->allows($ip), "{$ip} is public");
        }
    }
}
