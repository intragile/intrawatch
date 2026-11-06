<?php

namespace App\Monitoring\Net;

use Amp\Cancellation;
use Amp\Dns\DnsException;
use Amp\Dns\DnsRecord;
use Amp\Dns\DnsResolver;
use Amp\Socket\ConnectException;

/**
 * Resolves a host and keeps only the addresses the network policy permits. Literal IPs skip DNS.
 */
final class AllowedAddressResolver
{
    public function __construct(
        private readonly DnsResolver $resolver,
        private readonly NetworkPolicy $policy,
    ) {}

    /**
     * IPv4 addresses first, then IPv6.
     *
     * @return non-empty-list<string>
     *
     * @throws BlockedTarget When every resolved address is blocked.
     * @throws ConnectException When the host does not resolve.
     */
    public function resolve(string $host, ?int $typeRestriction = null, ?Cancellation $cancellation = null): array
    {
        $host = trim($host, '[]');

        if (@inet_pton($host) !== false) {
            $addresses = [$host];
        } else {
            try {
                $records = $this->resolver->resolve($host, $typeRestriction, $cancellation);
            } catch (DnsException $e) {
                throw new ConnectException('DNS resolution failed', 0, $e);
            }

            usort($records, static fn (DnsRecord $a, DnsRecord $b): int => $a->getType() <=> $b->getType());
            $addresses = array_values(array_unique(array_map(static fn (DnsRecord $r): string => $r->getValue(), $records)));
        }

        $allowed = array_values(array_filter($addresses, $this->policy->allows(...)));
        if ($allowed === []) {
            throw new BlockedTarget('Blocked target');
        }

        return $allowed;
    }
}
