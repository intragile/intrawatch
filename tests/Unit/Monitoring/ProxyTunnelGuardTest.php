<?php

namespace Tests\Unit\Monitoring;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Dns\DnsException;
use Amp\Dns\DnsRecord;
use Amp\Dns\DnsResolver;
use Amp\Socket\ConnectContext;
use Amp\Socket\ConnectException;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketConnector;
use App\Monitoring\Net\AllowedAddressResolver;
use App\Monitoring\Net\BlockedTarget;
use App\Monitoring\Net\GuardedConnector;
use App\Monitoring\Net\NetworkPolicy;
use App\Monitoring\Net\ProxyConnector;
use App\Monitoring\Net\ProxyFailure;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

/**
 * The local check of a proxied target: its verdicts are cached per host for a short time, a blocked verdict never
 * turns into an allowed one, and the time it takes is measured so the checker can keep it out of the latency.
 */
class ProxyTunnelGuardTest extends TestCase
{
    private const array BLOCKED = ['169.254.169.254/32'];

    private int $lookups = 0;

    public function test_repeated_checks_of_one_host_look_it_up_once_within_the_ttl(): void
    {
        $resolver = $this->guard(['example.test' => ['93.184.216.34']]);

        $resolver->assertNotBlocked('example.test');
        $resolver->assertNotBlocked('example.test');
        $resolver->assertNotBlocked('example.test');

        $this->assertSame(1, $this->lookups);
    }

    public function test_an_unresolvable_host_is_cached_and_allowed(): void
    {
        $resolver = $this->guard([]);

        $resolver->assertNotBlocked('internal.test');
        $resolver->assertNotBlocked('internal.test');

        $this->assertSame(1, $this->lookups);
    }

    public function test_a_lookup_timeout_is_cached(): void
    {
        $resolver = $this->guard([], hang: true);

        $started = microtime(true);
        $resolver->assertNotBlocked('slow.test');
        $first = microtime(true) - $started;

        $started = microtime(true);
        $resolver->assertNotBlocked('slow.test');
        $second = microtime(true) - $started;

        $this->assertGreaterThanOrEqual(AllowedAddressResolver::TUNNEL_TARGET_DNS_TIMEOUT - 0.05, $first);
        $this->assertLessThan(0.1, $second);
        $this->assertSame(1, $this->lookups);
    }

    public function test_a_blocked_hostname_stays_blocked_from_the_cache(): void
    {
        $resolver = $this->guard(['meta.test' => ['169.254.169.254']]);

        foreach ([1, 2] as $attempt) {
            try {
                $resolver->assertNotBlocked('meta.test');
                $this->fail("Attempt {$attempt} was not blocked");
            } catch (BlockedTarget) {
                // Expected on every attempt.
            }
        }

        $this->assertSame(1, $this->lookups);
    }

    public function test_verdicts_expire_after_their_ttl(): void
    {
        $now = 1000.0;
        $clock = function () use (&$now): float {
            return $now;
        };
        $resolver = $this->guard(['example.test' => ['93.184.216.34'], 'meta.test' => ['169.254.169.254']], now: $clock);

        $resolver->assertNotBlocked('example.test');
        $now += AllowedAddressResolver::ALLOWED_TTL - 1;
        $resolver->assertNotBlocked('example.test');
        $this->assertSame(1, $this->lookups);

        $now += 2;
        $resolver->assertNotBlocked('example.test');
        $this->assertSame(2, $this->lookups);

        $this->assertBlocked($resolver, 'meta.test');
        $now += AllowedAddressResolver::BLOCKED_TTL + 1;
        $this->assertBlocked($resolver, 'meta.test');
        $this->assertSame(4, $this->lookups);
    }

    public function test_an_unresolved_verdict_expires_sooner(): void
    {
        $now = 1000.0;
        $clock = function () use (&$now): float {
            return $now;
        };
        $resolver = $this->guard([], now: $clock);

        $resolver->assertNotBlocked('internal.test');
        $now += AllowedAddressResolver::UNRESOLVED_TTL + 1;
        $resolver->assertNotBlocked('internal.test');

        $this->assertSame(2, $this->lookups);
        $this->assertLessThan(AllowedAddressResolver::ALLOWED_TTL, AllowedAddressResolver::UNRESOLVED_TTL);
    }

    public function test_a_cancelled_check_is_not_cached(): void
    {
        $resolver = $this->guard([], hang: true);
        $deferred = new DeferredCancellation;
        async(function () use ($deferred): void {
            delay(0.05);
            $deferred->cancel();
        });

        try {
            $resolver->assertNotBlocked('slow.test', null, $deferred->getCancellation());
            $this->fail('The cancelled check was not cancelled');
        } catch (CancelledException) {
            // Expected.
        }

        $resolver->assertNotBlocked('slow.test');

        $this->assertSame(2, $this->lookups);
    }

    public function test_the_cache_key_ignores_case_but_not_the_type_restriction(): void
    {
        $resolver = $this->guard(['example.test' => ['93.184.216.34']]);

        $resolver->assertNotBlocked('example.test');
        $resolver->assertNotBlocked('EXAMPLE.Test');
        $this->assertSame(1, $this->lookups);

        $resolver->assertNotBlocked('example.test', DnsRecord::A);
        $this->assertSame(2, $this->lookups);
    }

    public function test_proxy_refuses_a_literal_metadata_ip_every_time_without_opening_the_tunnel(): void
    {
        $tunnelCalls = 0;
        $proxy = new ProxyConnector($this->tunnel(0.0, $tunnelCalls), $this->connector([]));

        foreach ([1, 2] as $attempt) {
            try {
                $proxy->connect('169.254.169.254:80');
                $this->fail("Attempt {$attempt} was not blocked");
            } catch (BlockedTarget) {
                // Expected on every attempt.
            }
        }

        $this->assertSame(0, $tunnelCalls);
        $this->assertSame(0, $this->lookups);
    }

    public function test_proxy_measures_only_the_time_spent_checking_the_target(): void
    {
        $tunnelCalls = 0;
        $proxy = new ProxyConnector($this->tunnel(0.3, $tunnelCalls), $this->connector(['slow.test' => ['93.184.216.34']], delay: 0.3));

        try {
            $proxy->connect('slow.test:80');
            $this->fail('The fake tunnel should fail');
        } catch (ProxyFailure) {
            // The fake tunnel never connects.
        }

        $guard = $proxy->guardNanoseconds() / 1e9;
        $this->assertSame(1, $tunnelCalls);
        $this->assertGreaterThanOrEqual(0.29, $guard);
        $this->assertLessThan(0.5, $guard);
    }

    public function test_proxy_measures_a_blocked_check_too(): void
    {
        $tunnelCalls = 0;
        $proxy = new ProxyConnector($this->tunnel(0.0, $tunnelCalls), $this->connector(['meta.test' => ['169.254.169.254']], delay: 0.2));

        try {
            $proxy->connect('meta.test:80');
            $this->fail('The target was not blocked');
        } catch (BlockedTarget) {
            // Expected.
        }

        $this->assertGreaterThanOrEqual(0.19, $proxy->guardNanoseconds() / 1e9);
        $this->assertSame(0, $tunnelCalls);
    }

    private function assertBlocked(AllowedAddressResolver $resolver, string $host): void
    {
        try {
            $resolver->assertNotBlocked($host);
            $this->fail("{$host} was not blocked");
        } catch (BlockedTarget) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param  array<string, non-empty-list<string>>  $hosts
     * @param  (\Closure(): float)|null  $now
     */
    private function guard(array $hosts, float $delay = 0.0, bool $hang = false, ?\Closure $now = null): AllowedAddressResolver
    {
        return new AllowedAddressResolver($this->resolver($hosts, $delay, $hang), new NetworkPolicy(self::BLOCKED), $now);
    }

    /**
     * @param  array<string, non-empty-list<string>>  $hosts
     */
    private function connector(array $hosts, float $delay = 0.0): GuardedConnector
    {
        return new GuardedConnector($this->resolver($hosts, $delay, false), new NetworkPolicy(self::BLOCKED));
    }

    /**
     * A resolver that counts its lookups in $this->lookups, answers from a fixed map after an optional delay, and
     * with $hang never answers.
     *
     * @param  array<string, non-empty-list<string>>  $hosts
     */
    private function resolver(array $hosts, float $delay, bool $hang): DnsResolver
    {
        $count = function (): void {
            $this->lookups++;
        };

        return new class($hosts, $delay, $hang, $count) implements DnsResolver
        {
            /**
             * @param  array<string, non-empty-list<string>>  $hosts
             */
            public function __construct(
                private readonly array $hosts,
                private readonly float $delay,
                private readonly bool $hang,
                private readonly \Closure $count,
            ) {}

            public function resolve(string $name, ?int $typeRestriction = null, ?Cancellation $cancellation = null): array
            {
                ($this->count)();
                if ($this->hang) {
                    delay(30, true, $cancellation);
                } elseif ($this->delay > 0) {
                    delay($this->delay, true, $cancellation);
                }

                $ips = $this->hosts[$name] ?? throw new DnsException("No such host {$name}");

                return array_map(static fn (string $ip): DnsRecord => new DnsRecord($ip, DnsRecord::A, null), $ips);
            }

            public function query(string $name, int $type, ?Cancellation $cancellation = null): array
            {
                return $this->resolve($name, $type, $cancellation);
            }
        };
    }

    /**
     * A tunnel that counts its calls, waits $delay and then fails, as an unreachable proxy would.
     */
    private function tunnel(float $delay, int &$calls): SocketConnector
    {
        $count = function () use (&$calls): void {
            $calls++;
        };

        return new class($delay, $count) implements SocketConnector
        {
            public function __construct(private readonly float $delay, private readonly \Closure $count) {}

            public function connect(SocketAddress|string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): Socket
            {
                ($this->count)();
                if ($this->delay > 0) {
                    delay($this->delay);
                }

                throw new ConnectException('The fake tunnel never connects');
            }
        };
    }
}
