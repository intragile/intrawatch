<?php

namespace Tests\Support;

use Amp\Cancellation;
use Amp\Dns\DnsException;
use Amp\Dns\DnsRecord;
use Amp\Dns\DnsResolver;
use Amp\Dns\MissingDnsRecordException;

/**
 * Answers record queries from a fixed table so tests never touch the network.
 */
final class RecordDnsResolver implements DnsResolver
{
    /** @var array<string, non-empty-list<string>> */
    public array $records = [];

    public int $queries = 0;

    /**
     * @param  non-empty-list<string>  $values
     */
    public function set(string $name, int $type, array $values): void
    {
        $this->records["{$name}|{$type}"] = $values;
    }

    public function resolve(string $name, ?int $typeRestriction = null, ?Cancellation $cancellation = null): array
    {
        return $this->query($name, $typeRestriction ?? DnsRecord::A, $cancellation);
    }

    public function query(string $name, int $type, ?Cancellation $cancellation = null): array
    {
        $this->queries++;

        if (str_starts_with($name, 'nx.')) {
            throw new DnsException("Name resolution failed for '{$name}'; server returned error code: 3 (NXDomain)", 3);
        }

        if (str_starts_with($name, 'broken.')) {
            throw new DnsException('server returned error code: 2 (ServFail) via 10.0.0.53', 2);
        }

        $values = $this->records["{$name}|{$type}"] ?? throw new MissingDnsRecordException("No records returned for '{$name}'");

        return array_map(static fn (string $value): DnsRecord => new DnsRecord($value, $type, 60), $values);
    }
}
