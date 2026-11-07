<?php

namespace App\Monitoring\Net;

use InvalidArgumentException;

/**
 * Decides which IP addresses a check may connect to, based on a list of blocked CIDR networks.
 */
final class NetworkPolicy
{
    /** @var list<array{0: string, 1: int}> Packed network address and prefix length. */
    private array $blocked = [];

    /**
     * @param  list<string>  $networks  Blocked IPv4 and IPv6 CIDRs; a bare address is treated as a single host.
     */
    public function __construct(private readonly array $networks = [])
    {
        foreach ($networks as $cidr) {
            $this->blocked[] = self::parse($cidr);
        }
    }

    /**
     * Loopback, private, shared (CGNAT), link-local, unique-local, IETF protocol assignment, benchmarking,
     * multicast and reserved networks, and the IPv6 translation prefixes that can embed any IPv4 address
     * (NAT64 and 6to4); registries are never there.
     */
    public const NON_PUBLIC_NETWORKS = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
        '192.168.0.0/16', '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '64:ff9b::/96', '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    /**
     * The configured policy plus every non-public network, for traffic to domain registries and IANA, whose
     * servers are always public. A redirect or a referral can then never reach an internal host.
     */
    public static function forRegistries(): self
    {
        return new self([...self::fromConfig()->networks, ...self::NON_PUBLIC_NETWORKS]);
    }

    public static function fromConfig(): self
    {
        return new self(array_values(array_filter(
            (array) config('monitoring.worker.blocked_networks', []),
            is_string(...),
        )));
    }

    /**
     * Whether a connection to the address is permitted. Anything that is not an IP address is denied.
     */
    public function allows(string $ip): bool
    {
        $packed = self::pack(trim($ip, '[]'));
        if ($packed === null) {
            return false;
        }

        foreach ($this->blocked as [$network, $prefix]) {
            if (strlen($network) === strlen($packed) && self::matches($packed, $network, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function parse(string $cidr): array
    {
        $cidr = trim($cidr);
        $parts = explode('/', $cidr, 2);
        $length = $parts[1] ?? null;

        $packed = self::pack($parts[0]);
        if ($packed === null) {
            throw new InvalidArgumentException("Invalid network address in \"{$cidr}\".");
        }

        $max = strlen($packed) * 8;
        if ($length === null) {
            $prefix = $max;
        } elseif (ctype_digit($length) && (int) $length <= $max) {
            $prefix = (int) $length;
        } else {
            throw new InvalidArgumentException("Invalid prefix length in \"{$cidr}\".");
        }

        return [$packed, $prefix];
    }

    /**
     * Packs an address to 4 or 16 bytes; IPv4-mapped IPv6 addresses collapse to their IPv4 form so they cannot
     * bypass an IPv4 rule.
     */
    private static function pack(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return substr($packed, 12);
        }

        return $packed;
    }

    private static function matches(string $address, string $network, int $prefix): bool
    {
        $bytes = intdiv($prefix, 8);
        if ($bytes > 0 && substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        $bits = $prefix % 8;
        if ($bits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $bits)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
