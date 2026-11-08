<?php

namespace Tests\Feature\Monitoring\Checks;

use App\Monitoring\Net\NetworkPolicy;
use Tests\TestCase;

/**
 * The built-in cloud metadata list is always added to the configured CHECK_BLOCKED_NETWORKS, unless
 * CHECK_ALLOW_METADATA turns it off for monitors; notification targets never reach it.
 */
class NetworkPolicyConfigTest extends TestCase
{
    private const METADATA_HOSTS = ['169.254.169.254', '169.254.170.2', '169.254.170.23', 'fd00:ec2::254', 'fd20:ce::254', '100.100.100.200'];

    public function test_custom_blocked_networks_extend_the_builtin_metadata_list(): void
    {
        config()->set('monitoring.worker.blocked_networks', ['10.0.0.0/8']);
        config()->set('monitoring.worker.allow_metadata', false);

        $policy = NetworkPolicy::fromConfig();

        foreach ([...self::METADATA_HOSTS, '10.1.1.1'] as $ip) {
            $this->assertFalse($policy->allows($ip), $ip);
        }
        $this->assertTrue($policy->allows('169.254.10.10'));
        $this->assertTrue($policy->allows('192.168.1.1'));
    }

    public function test_empty_blocked_networks_still_block_metadata(): void
    {
        config()->set('monitoring.worker.blocked_networks', []);
        config()->set('monitoring.worker.allow_metadata', false);

        $policy = NetworkPolicy::fromConfig();

        foreach (self::METADATA_HOSTS as $ip) {
            $this->assertFalse($policy->allows($ip), $ip);
        }
        $this->assertTrue($policy->allows('169.254.10.10'));
    }

    public function test_notification_policy_blocks_metadata_with_a_custom_list(): void
    {
        config()->set('monitoring.worker.blocked_networks', ['10.0.0.0/8']);
        config()->set('monitoring.worker.allow_metadata', false);

        $policy = NetworkPolicy::forNotifications();

        foreach ([...self::METADATA_HOSTS, '10.1.1.1', '169.254.10.10'] as $ip) {
            $this->assertFalse($policy->allows($ip), $ip);
        }
    }

    public function test_allow_metadata_lifts_the_block_for_monitors_only(): void
    {
        config()->set('monitoring.worker.blocked_networks', ['10.0.0.0/8']);
        config()->set('monitoring.worker.allow_metadata', true);

        $monitors = NetworkPolicy::fromConfig();
        $notifications = NetworkPolicy::forNotifications();

        foreach (self::METADATA_HOSTS as $ip) {
            $this->assertTrue($monitors->allows($ip), $ip);
            $this->assertFalse($notifications->allows($ip), $ip);
        }
        $this->assertFalse($monitors->allows('10.1.1.1'));
    }

    public function test_default_configuration_blocks_metadata_but_not_other_link_local_hosts(): void
    {
        $this->assertSame([], config('monitoring.worker.blocked_networks'));
        $this->assertFalse(config('monitoring.worker.allow_metadata'));

        $policy = NetworkPolicy::fromConfig();

        foreach (self::METADATA_HOSTS as $ip) {
            $this->assertFalse($policy->allows($ip), $ip);
        }
        $this->assertTrue($policy->allows('169.254.10.10'));
    }

    public function test_nat64_and_6to4_metadata_forms_are_blocked_for_monitors(): void
    {
        config()->set('monitoring.worker.blocked_networks', ['10.0.0.0/8']);
        config()->set('monitoring.worker.allow_metadata', false);

        $policy = NetworkPolicy::fromConfig();

        foreach (['64:ff9b::a9fe:a9fe', '2002:a9fe:a9fe::', '64:ff9b::a01:101'] as $ip) {
            $this->assertFalse($policy->allows($ip), $ip);
        }
        foreach (['64:ff9b::808:808', '2002:808:808::'] as $ip) {
            $this->assertTrue($policy->allows($ip), $ip);
        }
    }

    public function test_allow_metadata_also_lifts_the_nat64_metadata_form_for_monitors_only(): void
    {
        config()->set('monitoring.worker.blocked_networks', ['10.0.0.0/8']);
        config()->set('monitoring.worker.allow_metadata', true);

        $monitors = NetworkPolicy::fromConfig();
        $notifications = NetworkPolicy::forNotifications();

        foreach (['64:ff9b::a9fe:a9fe', '2002:a9fe:a9fe::'] as $ip) {
            $this->assertTrue($monitors->allows($ip), $ip);
            $this->assertFalse($notifications->allows($ip), $ip);
        }
        $this->assertFalse($monitors->allows('64:ff9b::a00:1'));
    }
}
