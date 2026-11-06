<?php

namespace App\Monitoring\Checks\Dns;

use Amp\Cache\NullCache;
use Amp\Dns\DnsConfig;
use Amp\Dns\DnsConfigException;
use Amp\Dns\DnsRecord;
use Amp\Dns\DnsResolver;
use Amp\Dns\Rfc1035StubDnsResolver;
use Amp\Dns\StaticDnsConfigLoader;
use Amp\Dns\UnixDnsConfigLoader;
use Amp\Dns\WindowsDnsConfigLoader;

/**
 * Builds the resolvers DNS checks query with.
 *
 * Caching is disabled so every check observes the name server's current answer. Resolvers are kept per
 * address, which keeps the number of sockets and timers bounded by the number of distinct name servers.
 */
class ResolverFactory
{
    /** @var array<string, DnsResolver> */
    private array $resolvers = [];

    /**
     * @param  ?string  $address  A name server as "ip", "ip:port" or "[ipv6]:port"; null selects the system resolver.
     *
     * @throws DnsConfigException When the system resolver configuration cannot be loaded.
     */
    public function make(?string $address): DnsResolver
    {
        return $this->resolvers[$address ?? ''] ??= new Rfc1035StubDnsResolver(
            new NullCache,
            new StaticDnsConfigLoader($address === null ? $this->systemConfig() : new DnsConfig(
                [$address],
                // The localhost entry is supplied so the config never falls back to a blocking lookup.
                [DnsRecord::A => ['localhost' => '127.0.0.1']],
            )),
        );
    }

    /**
     * The system configuration is loaded here rather than by the resolver: when the resolver's own loader
     * fails it silently falls back to a blocking lookup, which must never run inside the event loop. A
     * failure surfaces as an exception instead and is not cached, so the next check retries.
     */
    private function systemConfig(): DnsConfig
    {
        return (PHP_OS_FAMILY === 'Windows' ? new WindowsDnsConfigLoader : new UnixDnsConfigLoader)->loadConfig();
    }
}
