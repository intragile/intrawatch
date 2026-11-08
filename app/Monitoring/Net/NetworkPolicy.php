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
     * Cloud instance metadata endpoints, blocked for monitors on top of the configured list unless
     * monitoring.worker.allow_metadata (CHECK_ALLOW_METADATA) is set, and always blocked for notifications:
     * AWS IMDS, ECS task metadata, EKS Pod Identity, AWS IMDS over IPv6, GCP metadata over IPv6 and Alibaba Cloud.
     * The rest of 169.254.0.0/16 stays reachable for monitors, because intranet link-local devices are
     * legitimate targets. The IPv4 entries also cover their NAT64 (64:ff9b::/96) and 6to4 (2002::/16) forms,
     * and CHECK_ALLOW_METADATA lifts those together with the plain IPv4 address.
     */
    public const METADATA_NETWORKS = [
        '169.254.169.254/32', '169.254.170.2/32', '169.254.170.23/32', 'fd00:ec2::254/128', 'fd20:ce::254/128', '100.100.100.200/32',
    ];

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

    /**
     * Cloud metadata, link-local and translation networks, and the unspecified addresses (which connect to the
     * local host), that notification targets must never reach, on top of the configured list. The metadata list
     * applies even when CHECK_ALLOW_METADATA lifts it for monitors. Loopback and private networks stay allowed so
     * self-hosted receivers work.
     */
    public const NOTIFICATION_BLOCKED_NETWORKS = [
        ...self::METADATA_NETWORKS, '169.254.0.0/16', 'fe80::/10', '64:ff9b::/96', '2002::/16', '0.0.0.0/8', '::/128',
    ];

    public static function forNotifications(): self
    {
        return new self([...self::fromConfig()->networks, ...self::NOTIFICATION_BLOCKED_NETWORKS]);
    }

    /**
     * The monitor policy: the configured networks plus the built-in metadata list, which only
     * monitoring.worker.allow_metadata removes. The configured list extends the built-in one, never replaces it.
     */
    public static function fromConfig(): self
    {
        $configured = array_values(array_filter(
            (array) config('monitoring.worker.blocked_networks', []),
            is_string(...),
        ));

        return new self(config('monitoring.worker.allow_metadata', false) === true
            ? $configured
            : [...$configured, ...self::METADATA_NETWORKS]);
    }

    /**
     * Whether a connection to the address is permitted. Anything that is not an IP address is denied.
     * A NAT64 or 6to4 address is checked both as itself, so IPv6 rules on the translation prefixes keep
     * matching, and as the IPv4 address it embeds, so it cannot bypass an IPv4 rule.
     */
    public function allows(string $ip): bool
    {
        $packed = self::pack(trim($ip, '[]'));
        if ($packed === null) {
            return false;
        }

        $candidates = [$packed];
        $embedded = self::embeddedIpv4($packed);
        if ($embedded !== null) {
            $candidates[] = $embedded;
        }

        foreach ($candidates as $address) {
            foreach ($this->blocked as [$network, $prefix]) {
                if (strlen($network) === strlen($address) && self::matches($address, $network, $prefix)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The IPv4 address embedded in a well-known-prefix NAT64 (64:ff9b::/96, last 32 bits) or a 6to4
     * (2002::/16, bits 16-48) address. A translator or relay forwards such an address to the embedded IPv4
     * host, so it could otherwise bypass an IPv4 rule; blocking the whole prefix instead would cut monitors
     * off from legitimate public targets behind NAT64. This deliberately stays out of pack(), so explicit
     * IPv6 rules on these prefixes still apply.
     */
    private static function embeddedIpv4(string $packed): ?string
    {
        if (strlen($packed) !== 16) {
            return null;
        }

        if (str_starts_with($packed, "\x00\x64\xff\x9b".str_repeat("\0", 8))) {
            return substr($packed, 12, 4);
        }

        if (str_starts_with($packed, "\x20\x02")) {
            return substr($packed, 2, 4);
        }

        return null;
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
     * Packs an address to 4 or 16 bytes; IPv4-mapped and IPv4-compatible IPv6 addresses collapse to their IPv4
     * form so they cannot bypass an IPv4 rule. `::` and `::1` keep their IPv6 meaning.
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

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 12)) && strcmp(substr($packed, 12), "\0\0\0\1") > 0) {
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
