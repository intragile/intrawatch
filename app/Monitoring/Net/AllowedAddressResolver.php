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
 *
 * The verdicts of the best-effort proxy target check (assertNotBlocked() for a hostname) are kept for a few seconds
 * per host, so a redirect chain or repeated checks do not repeat the local lookup on every hop. resolve(), which a
 * direct connection relies on for resolve-then-connect, is never cached.
 */
final class AllowedAddressResolver
{
    /**
     * Seconds the best-effort lookup of a proxied target may take. Workers that reach the network only through
     * their proxy often have no usable resolver; the lookup must not eat the check's connect timeout there.
     */
    public const float TUNNEL_TARGET_DNS_TIMEOUT = 1.0;

    /** Seconds an allowed (resolved, nothing blocked) proxy target verdict is reused, at most. */
    public const float ALLOWED_TTL = 30.0;

    /** Seconds a verdict for a proxy target that did not resolve locally, or timed out, is reused. */
    public const float UNRESOLVED_TTL = 10.0;

    /** Seconds a blocked proxy target verdict is reused. A cached block is always a block. */
    public const float BLOCKED_TTL = 60.0;

    /** Most proxy target verdicts kept; beyond that the expired ones and then the oldest ones are dropped. */
    public const int MAX_VERDICTS = 1024;

    /** @var \Closure(): float */
    private readonly \Closure $now;

    /**
     * Proxy target verdicts by "host|type restriction": whether the host is blocked, and when the verdict expires.
     *
     * @var array<string, array{blocked: bool, expires: float}>
     */
    private array $verdicts = [];

    /**
     * @param  (\Closure(): float)|null  $now  Monotonic seconds; tests pass a fake clock.
     */
    public function __construct(
        private readonly DnsResolver $resolver,
        private readonly NetworkPolicy $policy,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): float => hrtime(true) / 1e9;
    }

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
     * A hostname's verdict is cached for a few seconds (see the *_TTL constants). That is safe: a cached block is
     * still a block, and an allowed verdict was only ever best effort, because the proxy resolves the name itself.
     * A cancelled check caches nothing. Literal and numeric forms are always checked exactly, without the cache.
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
            $this->assertHostNotBlocked($host, $typeRestriction, $cancellation ?? new NullCancellation);

            return;
        }

        foreach ($addresses as $address) {
            if (! $this->policy->allows($address)) {
                throw new BlockedTarget('Blocked target');
            }
        }
    }

    /**
     * The hostname branch of assertNotBlocked(), with its verdict cache.
     *
     * @throws BlockedTarget
     * @throws CancelledException
     */
    private function assertHostNotBlocked(string $host, ?int $typeRestriction, Cancellation $cancellation): void
    {
        $key = strtolower($host).'|'.($typeRestriction ?? '*');
        $now = ($this->now)();

        $cached = $this->verdicts[$key] ?? null;
        if ($cached !== null && $cached['expires'] > $now) {
            if ($cached['blocked']) {
                throw new BlockedTarget('Blocked target');
            }

            return;
        }

        try {
            $records = $this->resolver->resolve(
                $host,
                $typeRestriction,
                new CompositeCancellation($cancellation, new TimeoutCancellation(self::TUNNEL_TARGET_DNS_TIMEOUT)),
            );
        } catch (DnsException) {
            $this->remember($key, false, self::UNRESOLVED_TTL, $now);

            return;
        } catch (CancelledException $e) {
            // Only our own lookup deadline expired: let the proxy resolve the name. A cancelled check propagates,
            // and caches nothing.
            $cancellation->throwIfRequested();
            $this->remember($key, false, self::UNRESOLVED_TTL, $now);

            return;
        }

        foreach ($records as $record) {
            if (! $this->policy->allows($record->getValue())) {
                $this->remember($key, true, self::BLOCKED_TTL, $now);

                throw new BlockedTarget('Blocked target');
            }
        }

        $ttl = self::ALLOWED_TTL;
        foreach ($records as $record) {
            $recordTtl = $record->getTtl();
            if ($recordTtl !== null) {
                $ttl = min($ttl, (float) $recordTtl);
            }
        }
        $this->remember($key, false, $ttl, $now);
    }

    private function remember(string $key, bool $blocked, float $ttl, float $now): void
    {
        if ($ttl <= 0) {
            return;
        }

        unset($this->verdicts[$key]);
        if (count($this->verdicts) >= self::MAX_VERDICTS) {
            $this->verdicts = array_filter($this->verdicts, static fn (array $v): bool => $v['expires'] > $now);
        }
        while (count($this->verdicts) >= self::MAX_VERDICTS) {
            unset($this->verdicts[array_key_first($this->verdicts)]);
        }

        $this->verdicts[$key] = ['blocked' => $blocked, 'expires' => $now + $ttl];
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
