<?php

namespace Tests\Unit\Monitoring;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Dns\DnsException;
use Amp\Dns\DnsResolver;
use App\Monitoring\Net\AllowedAddressResolver;
use App\Monitoring\Net\NetworkPolicy;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

class AllowedAddressResolverTest extends TestCase
{
    public function test_slow_local_dns_does_not_consume_the_proxied_check_timeout(): void
    {
        // A worker that only reaches the network through its proxy often has no working resolver: the lookup must
        // give up quickly and let the proxy resolve the name, instead of eating the check's connect timeout.
        $resolver = new AllowedAddressResolver($this->hangingResolver(), new NetworkPolicy(NetworkPolicy::METADATA_NETWORKS));

        $started = microtime(true);
        $resolver->assertNotBlocked('example.com');
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(AllowedAddressResolver::TUNNEL_TARGET_DNS_TIMEOUT + 0.5, $elapsed);
    }

    public function test_cancelling_the_check_still_cancels_the_lookup(): void
    {
        $resolver = new AllowedAddressResolver($this->hangingResolver(), new NetworkPolicy([]));
        $deferred = new DeferredCancellation;
        async(function () use ($deferred): void {
            delay(0.05);
            $deferred->cancel();
        });

        $this->expectException(CancelledException::class);
        $resolver->assertNotBlocked('example.com', null, $deferred->getCancellation());
    }

    private function hangingResolver(): DnsResolver
    {
        return new class implements DnsResolver
        {
            public function resolve(string $name, ?int $typeRestriction = null, ?Cancellation $cancellation = null): array
            {
                delay(30, true, $cancellation);

                throw new DnsException('The resolver never answers');
            }

            public function query(string $name, int $type, ?Cancellation $cancellation = null): array
            {
                return $this->resolve($name, $type, $cancellation);
            }
        };
    }
}
