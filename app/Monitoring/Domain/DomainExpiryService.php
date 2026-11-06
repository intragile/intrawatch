<?php

namespace App\Monitoring\Domain;

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Future;
use Amp\Socket\ConnectException;
use Amp\TimeoutCancellation;
use App\Models\DomainLookup;
use App\Monitoring\Net\BlockedTarget;
use App\Monitoring\Worker\Clock;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Amp\async;

/**
 * Finds when a domain's registration expires: over RDAP when the TLD's registry offers it, over WHOIS
 * otherwise.
 *
 * Only the registrable domain of a host is ever queried, never a public suffix. Answers are cached in memory and
 * in `domain_lookups`: for a day, or for an hour after a failure. Concurrent lookups of one domain within a
 * process share a single registry query, and every query runs to its own deadline regardless of who waits for
 * it, so an answer that arrives after a caller gave up still fills the cache.
 *
 * The cache table is read once per domain and process and written once per registry query. A database that is
 * unavailable only costs the cache; lookups keep working from memory.
 */
class DomainExpiryService
{
    public const TTL_SECONDS = 86400;

    public const FAILURE_TTL_SECONDS = 3600;

    private const WHOIS_REFERRAL_PREFIX = '_whois.';

    /** @var array<string, DomainExpiry> */
    private array $memory = [];

    /** @var array<string, Future<DomainExpiry>> */
    private array $inflight = [];

    /**
     * @param  array<string, string>  $whoisServers  TLD to WHOIS server (`host` or `host:port`), overriding discovery.
     * @param  string  $ianaWhois  The server asked for a TLD's WHOIS server.
     * @param  float  $lookupTimeout  Seconds a registry query may take in total, WHOIS discovery included.
     */
    public function __construct(
        private readonly RegistrableDomains $domains,
        private readonly RdapBootstrap $bootstrap,
        private readonly RdapClient $rdap,
        private readonly WhoisClient $whois,
        private readonly Clock $clock,
        private readonly array $whoisServers = [],
        private readonly string $ianaWhois = 'whois.iana.org',
        private readonly float $lookupTimeout = 30.0,
    ) {}

    /**
     * The registrable domain that is queried for a host, or null when nothing would be queried.
     */
    public function registrableDomain(string $host): ?string
    {
        return $this->domains->registrable($host);
    }

    /**
     * Waits for the expiry of the host's registrable domain, from the cache or the registry.
     *
     * @throws NotRegistrableDomain
     * @throws CancelledException When the cancellation is requested first; the lookup itself continues.
     */
    public function lookup(string $host, Cancellation $cancellation): DomainExpiry
    {
        $domain = $this->registrableDomain($host) ?? throw new NotRegistrableDomain;

        return $this->fresh($domain) ?? $this->start($domain)->await($cancellation);
    }

    /**
     * The cached expiry of the host's registrable domain, without waiting. On a miss or a stale entry this
     * starts a lookup in the background and returns null; a later call returns its answer.
     */
    public function peek(string $host): ?DomainExpiry
    {
        $domain = $this->registrableDomain($host);
        if ($domain === null) {
            return null;
        }

        $fresh = $this->fresh($domain);
        if ($fresh === null) {
            $this->start($domain)->ignore();
        }

        return $fresh;
    }

    private function fresh(string $domain): ?DomainExpiry
    {
        if (isset($this->inflight[$domain])) {
            return null;
        }

        $cached = $this->memory[$domain] ?? $this->fromDatabase($domain);
        if ($cached === null) {
            return null;
        }
        $this->memory[$domain] = $cached;

        return $cached->isFreshAt($this->clock->now(), self::TTL_SECONDS, self::FAILURE_TTL_SECONDS) ? $cached : null;
    }

    /**
     * @return Future<DomainExpiry>
     */
    private function start(string $domain): Future
    {
        return $this->inflight[$domain] ??= async(function () use ($domain): DomainExpiry {
            try {
                $expiry = $this->query($domain);
                $this->remember($expiry);

                return $expiry;
            } finally {
                unset($this->inflight[$domain]);
            }
        });
    }

    /**
     * Never throws: every failure becomes an error answer.
     */
    private function query(string $domain): DomainExpiry
    {
        $tld = substr($domain, (int) strrpos($domain, '.') + 1);
        $base = $this->bootstrap->baseFor($tld);
        $source = $base === null ? DomainExpiry::SOURCE_WHOIS : DomainExpiry::SOURCE_RDAP;
        $deadline = new TimeoutCancellation($this->lookupTimeout);

        try {
            return $base !== null
                ? $this->rdap->lookup($domain, $base, $this->lookupTimeout, $deadline, $this->clock->now())
                : $this->whoisLookup($domain, $tld, $deadline);
        } catch (CancelledException) {
            return DomainExpiry::error($domain, $source, 'Timed out after '.RdapClient::seconds($this->lookupTimeout).' s', $this->clock->now());
        } catch (Throwable $e) {
            // Network failures are expected answers; anything else points at a defect and is logged.
            if (! $e instanceof StreamException) {
                Log::warning('Domain lookup failed unexpectedly.', ['domain' => $domain, 'exception' => $e::class]);
            }

            return DomainExpiry::error($domain, $source, $this->reason($e), $this->clock->now());
        }
    }

    private function whoisLookup(string $domain, string $tld, Cancellation $cancellation): DomainExpiry
    {
        $server = $this->whoisServer($tld, $cancellation);
        $answer = $this->whois->query($server, $domain, $cancellation);
        $now = $this->clock->now();

        if (trim($answer) === '') {
            return DomainExpiry::error($domain, DomainExpiry::SOURCE_WHOIS, 'Empty WHOIS response', $now);
        }

        $record = WhoisParser::parse($answer);
        $status = match (true) {
            $record->expiresAt !== null => DomainExpiry::STATUS_OK,
            $record->notFound => DomainExpiry::STATUS_NOT_FOUND,
            $record->throttled => DomainExpiry::STATUS_ERROR,
            default => DomainExpiry::STATUS_UNKNOWN,
        };

        return new DomainExpiry(
            $domain,
            DomainExpiry::SOURCE_WHOIS,
            $status,
            $record->expiresAt,
            $record->registrar,
            $status === DomainExpiry::STATUS_ERROR ? 'WHOIS rate limit' : null,
            $now,
        );
    }

    /**
     * The configured server, else the one IANA refers to, else the registry's conventional `whois.nic.{tld}`.
     * Discovered servers are cached like lookups.
     */
    private function whoisServer(string $tld, Cancellation $cancellation): string
    {
        if (isset($this->whoisServers[$tld])) {
            return $this->whoisServers[$tld];
        }

        $key = self::WHOIS_REFERRAL_PREFIX.$tld;
        $cached = $this->fresh($key);
        if ($cached !== null && $cached->registrar !== null) {
            return $cached->registrar;
        }

        $server = WhoisParser::referral($this->whois->query($this->ianaWhois, $tld, $cancellation)) ?? "whois.nic.{$tld}";
        $this->remember(new DomainExpiry($key, DomainExpiry::SOURCE_WHOIS, DomainExpiry::STATUS_OK, null, $server, null, $this->clock->now()));

        return $server;
    }

    private function remember(DomainExpiry $expiry): void
    {
        $this->memory[$expiry->domain] = $expiry;

        try {
            DomainLookup::query()->upsert([$expiry->toRow()], ['domain']);
        } catch (Throwable $e) {
            Log::warning('Domain lookup could not be cached.', ['domain' => $expiry->domain, 'exception' => $e::class]);
        }
    }

    private function fromDatabase(string $domain): ?DomainExpiry
    {
        try {
            $row = DomainLookup::query()->find($domain);
        } catch (Throwable) {
            return null;
        }

        return $row === null ? null : DomainExpiry::fromModel($row);
    }

    /**
     * A short reason that never carries a host name, an address or a server message.
     */
    private function reason(Throwable $e): string
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof BlockedTarget) {
                return 'Blocked target';
            }
            if ($current instanceof ConnectException) {
                return 'Connection failed';
            }
        }

        if ($e instanceof StreamException) {
            return 'Connection closed';
        }

        $class = $e::class;
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
