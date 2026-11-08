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

    public function test_ipv4_compatible_ipv6_is_classified_as_ipv4(): void
    {
        $policy = new NetworkPolicy(['127.0.0.0/8', '10.0.0.0/8', '::1/128', '::/128']);

        foreach (['::127.0.0.1', '::7f00:1', '::a00:1', '0:0:0:0:0:0:7f00:1', '::', '::1'] as $ip) {
            $this->assertFalse($policy->allows($ip), "{$ip} must be classified like its IPv4 or loopback equivalent");
        }
        $this->assertTrue($policy->allows('::808:808'), 'a public IPv4-compatible address stays allowed');
        $this->assertTrue((new NetworkPolicy(['0.0.0.0/8']))->allows('::1'), 'the IPv6 loopback is not read as 0.0.0.1');
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

    public function test_builtin_metadata_networks_are_exactly_the_six_cloud_endpoints(): void
    {
        $this->assertSame(
            ['169.254.169.254/32', '169.254.170.2/32', '169.254.170.23/32', 'fd00:ec2::254/128', 'fd20:ce::254/128', '100.100.100.200/32'],
            NetworkPolicy::METADATA_NETWORKS,
        );
    }

    public function test_builtin_metadata_policy_blocks_only_the_metadata_hosts(): void
    {
        $policy = new NetworkPolicy(NetworkPolicy::METADATA_NETWORKS);

        foreach (['169.254.169.254', '169.254.170.2', '169.254.170.23', 'fd00:ec2::254', 'fd20:ce::254', '100.100.100.200', '::ffff:169.254.170.2', '::ffff:100.100.100.200'] as $ip) {
            $this->assertFalse($policy->allows($ip), $ip);
        }

        foreach (['169.254.10.10', '169.254.169.253', '169.254.170.3', '100.100.100.201', 'fd00:ec2::255', 'fe80::1'] as $ip) {
            $this->assertTrue($policy->allows($ip), $ip);
        }
    }

    public function test_nat64_and_6to4_cannot_bypass_the_metadata_rule(): void
    {
        $policy = new NetworkPolicy(NetworkPolicy::METADATA_NETWORKS);

        foreach (['64:ff9b::a9fe:a9fe', '64:ff9b::169.254.169.254', '2002:a9fe:a9fe::', '2002:a9fe:a9fe:1::5', '64:ff9b::6464:64c8', '2002:a9fe:aa02::'] as $ip) {
            $this->assertFalse($policy->allows($ip), "{$ip} embeds a metadata address");
        }
        foreach (['64:ff9b::a9fe:a9fd', '2002:a9fe:a9fd::'] as $ip) {
            $this->assertTrue($policy->allows($ip), "{$ip} embeds a non-metadata address");
        }
    }

    public function test_nat64_and_6to4_cannot_bypass_a_configured_ipv4_rule(): void
    {
        $policy = new NetworkPolicy(['10.0.0.0/8', '192.168.0.0/16']);

        foreach (['64:ff9b::a00:1', '64:ff9b::10.255.0.1', '2002:a00:1::', '2002:c0a8:101::1'] as $ip) {
            $this->assertFalse($policy->allows($ip), "{$ip} embeds a blocked IPv4 address");
        }
        foreach (['64:ff9b::b00:1', '2002:b00:1::'] as $ip) {
            $this->assertTrue($policy->allows($ip), "{$ip} embeds an allowed IPv4 address");
        }
    }

    public function test_public_ipv4_behind_nat64_or_6to4_stays_reachable(): void
    {
        $policy = new NetworkPolicy([...NetworkPolicy::METADATA_NETWORKS, '10.0.0.0/8']);

        foreach (['64:ff9b::808:808', '64:ff9b::8.8.8.8', '2002:0808:0808::', '2002:808:808::1'] as $ip) {
            $this->assertTrue($policy->allows($ip), "{$ip} embeds a public address");
        }
    }

    public function test_ipv6_rules_still_match_translation_addresses(): void
    {
        $policy = new NetworkPolicy(['64:ff9b::/96', '2002::/16']);

        $this->assertFalse($policy->allows('64:ff9b::808:808'));
        $this->assertFalse($policy->allows('2002:808:808::'));
    }

    public function test_non_translation_ipv6_is_not_unpacked(): void
    {
        $policy = new NetworkPolicy(['10.0.0.0/8']);

        $this->assertTrue($policy->allows('2001:db8::a00:1'));
        $this->assertTrue($policy->allows('64:ff9c::a00:1'));
    }
}
