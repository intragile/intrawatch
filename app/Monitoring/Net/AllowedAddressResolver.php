<?php

namespace App\Monitoring\Net;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\Dns\DnsException;
use Amp\Dns\DnsRecord;
use Amp\Dns\DnsResolver;
use Amp\NullCancellation;
use Amp\Socket\ConnectException;
use Amp\TimeoutCancellation;

/**
 * Resolves a host and keeps only the addresses the network policy permits. Literal IPs skip DNS.
 */
final class AllowedAddressResolver
{
    /**
     * Seconds the best-effort lookup of a proxied target may take. Workers that reach the network only through
     * their proxy often have no usable resolver; the lookup must not eat the check's connect timeout there.
     */
    public const float TUNNEL_TARGET_DNS_TIMEOUT = 1.0;

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

    /**
     * Refuses a host that a proxy will connect to on our behalf. A literal IP must be permitted. A hostname is
     * resolved locally on a best-effort basis: when any of its addresses is blocked the host is refused, because
     * the proxy picks the address itself; when it does not resolve locally it is allowed, since the proxy may
     * resolve names the worker cannot. The lookup gives up after TUNNEL_TARGET_DNS_TIMEOUT for the same reason. The
     * proxy may still resolve the name differently.
     *
     * @throws BlockedTarget When the address, or any locally resolved address, is blocked.
     * @throws CancelledException When the check is cancelled during resolution.
     */
    public function assertNotBlocked(string $host, ?int $typeRestriction = null, ?Cancellation $cancellation = null): void
    {
        $host = trim($host, '[]');

        if (@inet_pton($host) !== false) {
            $addresses = [$host];
        } elseif (self::looksNumeric($host)) {
            // The proxy resolves the name with inet_aton/getaddrinfo, which reads decimal, hex, octal and short
            // dotted forms (2852039166, 0xa9fea9fe, 169.254.43518) as IPv4. Check the address it will reach.
            $address = self::inetAton($host);
            if ($address === null) {
                throw new BlockedTarget('Blocked target');
            }
            $addresses = [$address];
        } else {
            $cancellation ??= new NullCancellation;
            try {
                $records = $this->resolver->resolve(
                    $host,
                    $typeRestriction,
                    new CompositeCancellation($cancellation, new TimeoutCancellation(self::TUNNEL_TARGET_DNS_TIMEOUT)),
                );
            } catch (DnsException) {
                return;
            } catch (CancelledException $e) {
                // Only our own lookup deadline expired: let the proxy resolve the name. A cancelled check propagates.
                $cancellation->throwIfRequested();

                return;
            }

            $addresses = array_map(static fn (DnsRecord $r): string => $r->getValue(), $records);
        }

        foreach ($addresses as $address) {
            if (! $this->policy->allows($address)) {
                throw new BlockedTarget('Blocked target');
            }
        }
    }

    /**
     * True when every dot-separated label is a number, so libc would treat the host as an IPv4 address (or reject
     * it) rather than look it up as a name. One trailing dot is ignored.
     */
    private static function looksNumeric(string $host): bool
    {
        $host = str_ends_with($host, '.') ? substr($host, 0, -1) : $host;
        if ($host === '') {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if (preg_match('/^(0x[0-9a-f]*|[0-9]+)$/i', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Parses a host the way inet_aton does: 1-4 parts, each decimal, octal (leading 0) or hex (0x), the last part
     * filling the remaining bytes. Returns dotted IPv4, or null when inet_aton would reject it.
     */
    private static function inetAton(string $host): ?string
    {
        $host = str_ends_with($host, '.') ? substr($host, 0, -1) : $host;
        $parts = explode('.', $host);
        if (count($parts) > 4) {
            return null;
        }

        $values = [];
        foreach ($parts as $part) {
            if (preg_match('/^0x([0-9a-f]*)$/i', $part, $m) === 1) {
                $digits = ltrim($m[1], '0');
                $value = strlen($digits) > 8 ? null : (int) hexdec($digits === '' ? '0' : $digits);
            } elseif (preg_match('/^0([0-7]*)$/', $part, $m) === 1) {
                $digits = ltrim($m[1], '0');
                $value = strlen($digits) > 11 ? null : (int) octdec($digits === '' ? '0' : $digits);
            } elseif (preg_match('/^[1-9][0-9]*$/', $part) === 1) {
                $value = strlen($part) > 10 ? null : (int) $part;
            } else {
                return null;
            }
            if ($value === null) {
                return null;
            }
            $values[] = $value;
        }

        $last = array_pop($values);
        foreach ($values as $value) {
            if ($value > 0xFF) {
                return null;
            }
        }
        $lastBits = 8 * (4 - count($values));
        if ($last >= (1 << $lastBits)) {
            return null;
        }

        $int = $last;
        foreach ($values as $i => $value) {
            $int |= $value << (8 * (3 - $i));
        }

        return long2ip($int);
    }
}
