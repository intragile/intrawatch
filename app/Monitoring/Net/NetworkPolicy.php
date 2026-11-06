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
     * @param  list<string>  $blockedNetworks  IPv4 and IPv6 CIDRs; a bare address is treated as a single host.
     */
    public function __construct(array $blockedNetworks = [])
    {
        foreach ($blockedNetworks as $cidr) {
            $this->blocked[] = self::parse($cidr);
        }
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
