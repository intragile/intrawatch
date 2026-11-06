<?php

namespace Tests\Support;

use Amp\Cancellation;
use Amp\Dns\DnsException;
use Amp\Dns\DnsRecord;
use Amp\Dns\DnsResolver;

/**
 * Resolves names from a fixed map so tests never touch the network.
 */
final class StubDnsResolver implements DnsResolver
{
    /**
     * @param  array<string, non-empty-list<string>>  $hosts
     */
    public function __construct(private readonly array $hosts = []) {}

    public function resolve(string $name, ?int $typeRestriction = null, ?Cancellation $cancellation = null): array
    {
        $ips = $this->hosts[$name] ?? throw new DnsException("No such host {$name}");

        return array_map(
            static fn (string $ip): DnsRecord => new DnsRecord($ip, str_contains($ip, ':') ? DnsRecord::AAAA : DnsRecord::A, null),
            $ips,
        );
    }

    public function query(string $name, int $type, ?Cancellation $cancellation = null): array
    {
        return $this->resolve($name, $type, $cancellation);
    }
}
