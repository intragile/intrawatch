<?php

namespace App\Monitoring\Domain;

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Future;
use Amp\Socket\ConnectException;
use Amp\Sync\LocalKeyedSemaphore;
use Amp\TimeoutCancellation;
use App\Enums\MonitorType;
use App\Models\DomainLookup;
use App\Monitoring\Engine\DatabaseErrors;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Net\BlockedTarget;
use App\Monitoring\Types\Hostname;
use App\Monitoring\Worker\Clock;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Amp\async;

/**
 * Finds when a domain's registration expires: over RDAP when the TLD's registry offers it, over WHOIS
 * otherwise.
 *
 * Only the registrable domain of a host is ever queried, never a public suffix. Answers are kept in memory for
 * a day, or for an hour after a failure, each with ±10 % jitter so retries spread out. Concurrent lookups of
 * one domain within a process share a single registry query, at most two queries run against one registry
 * server at a time, and every query runs to its own deadline regardless of who waits for it, so an answer that
 * arrives after a caller gave up still fills the cache.
 *
 * Checks never touch the database. The monitor loader preloads the cached rows of the monitors it hands out
 * with warm(), and new answers are written in one statement by flushPending(), which the outcome buffer calls
 * after each successful flush. Rows whose write fails stay pending for the next one.
 */
class DomainExpiryService
{
    public const TTL_SECONDS = 86400;

    public const FAILURE_TTL_SECONDS = 3600;

    /** Concurrent queries per registry server. */
    public const REGISTRY_CONCURRENCY = 2;

    /** Cache keys of WHOIS server discoveries; the colon keeps them apart from every possible domain name. */
    public const WHOIS_KEY_PREFIX = 'whois:';

    private const JITTER = 0.1;

    private const MAX_PENDING_WRITES = 10_000;

    private const QUERY_CHUNK = 500;

    /** @var array<string, DomainExpiry> */
    private array $memory = [];

    /** @var array<string, float> TTL multiplier per cached entry. */
    private array $ttlFactor = [];

    /** @var array<string, array{domain: string, source: string, status: string, expires_at: ?string, registrar: ?string, error: ?string, checked_at: string}> */
    private array $pending = [];

    /** @var array<string, Future<DomainExpiry>> */
    private array $inflight = [];

    private readonly LocalKeyedSemaphore $registries;

    /** @var Closure(): float */
    private readonly Closure $jitter;

    /**
     * @param  array<string, string>  $whoisServers  TLD to WHOIS server (`host` or `host:port`), overriding discovery.
     * @param  string  $ianaWhois  The server asked for a TLD's WHOIS server.
     * @param  float  $lookupTimeout  Seconds one registry query may take once it is allowed to start.
     * @param  ?Closure(): float  $jitter  TTL multiplier for a new cache entry; random within ±10 % by default.
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
        ?Closure $jitter = null,
    ) {
        $this->registries = new LocalKeyedSemaphore(self::REGISTRY_CONCURRENCY);
        $this->jitter = $jitter ?? static fn (): float => 1 - self::JITTER + 2 * self::JITTER * mt_rand() / mt_getrandmax();
    }

    /**
     * The registrable domain that is queried for a host, or null when nothing would be queried.
     */
    public function registrableDomain(string $host): ?string
    {
        return $this->domains->registrable($host);
    }

    /**
     * Waits for the expiry of the host's registrable domain, from memory or the registry.
     *
     * @throws NotRegistrableDomain
     * @throws CancelledException When the cancellation is requested first; the lookup itself continues.
     */
    public function lookup(string $host, Cancellation $cancellation): DomainExpiry
    {
        $domain = $this->registrableDomain($host) ?? throw new NotRegistrableDomain;

        return $this->fresh($domain) ?? $this->startLookup($domain)->await($cancellation);
    }

    /**
     * The expiry of the host's registrable domain without waiting. On a miss or a stale entry this starts a
     * lookup in the background. A stale answer with a date still ahead is returned meanwhile; otherwise null.
     */
    public function peek(string $host): ?DomainExpiry
    {
        $domain = $this->registrableDomain($host);
        if ($domain === null) {
            return null;
        }

        $fresh = $this->fresh($domain);
        if ($fresh !== null) {
            return $fresh;
        }

        $this->startLookup($domain)->ignore();

        return $this->stale($host);
    }

    /**
     * The last answer for the host's registrable domain, fresh or not, when it holds an expiry date that has not
     * passed. Callers use it for facts only, never to report a domain as expired or missing.
     */
    public function stale(string $host): ?DomainExpiry
    {
        $domain = $this->registrableDomain($host);
        $last = $domain === null ? null : ($this->memory[$domain] ?? null);

        return $last !== null && $last->status === DomainExpiry::STATUS_OK && ! $last->isExpired($this->clock->now()) ? $last : null;
    }

    /**
     * The fresh cached expiry of the host's registrable domain, without starting a lookup.
     */
    public function cached(string $host): ?DomainExpiry
    {
        $domain = $this->registrableDomain($host);

        return $domain === null ? null : $this->fresh($domain);
    }

    /**
     * Loads the cached rows the given monitors need into memory with one query, and picks up refreshed suffix
     * and bootstrap lists. Called by the monitor loader, outside of any check.
     *
     * @param  iterable<MonitorSpec>  $specs
     */
    public function warm(iterable $specs): void
    {
        $this->domains->refresh();
        $this->bootstrap->refresh();

        $keys = [];
        foreach ($specs as $spec) {
            $host = self::hostOf($spec);
            $domain = $host === null ? null : $this->registrableDomain($host);
            if ($domain === null) {
                continue;
            }

            $keys[$domain] = true;
            $tld = self::tld($domain);
            if ($this->bootstrap->baseFor($tld) === null) {
                $keys[self::WHOIS_KEY_PREFIX.$tld] = true;
            }
        }

        $missing = array_values(array_filter(array_keys($keys), fn (string $key): bool => ! isset($this->memory[$key])));
        if ($missing === []) {
            return;
        }

        try {
            foreach (array_chunk($missing, self::QUERY_CHUNK) as $chunk) {
                foreach (DomainLookup::query()->whereIn('domain', $chunk)->get() as $row) {
                    if (! isset($this->memory[$row->domain])) {
                        $this->keep(DomainExpiry::fromModel($row));
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('Cached domain lookups could not be loaded.', ['exception' => $e::class]);
        }
    }

    /**
     * Writes the answers gathered since the last call. Called after the outcome buffer flushed.
     *
     * @return bool False when the write failed; the rows stay pending.
     */
    public function flushPending(): bool
    {
        if ($this->pending === []) {
            return true;
        }

        foreach (array_chunk($this->pending, self::QUERY_CHUNK, true) as $chunk) {
            try {
                $this->upsert($chunk);
                $this->pending = array_diff_key($this->pending, $chunk);

                continue;
            } catch (Throwable $e) {
                if (! DatabaseErrors::rejectsData($e)) {
                    return $this->writeFailed($e);
                }
            }

            // The database rejected something in the chunk: write row by row and drop only the rows it rejects.
            foreach ($chunk as $key => $row) {
                try {
                    $this->upsert([$key => $row]);
                } catch (Throwable $e) {
                    if (! DatabaseErrors::rejectsData($e)) {
                        return $this->writeFailed($e);
                    }
                    Log::error('Dropped a domain lookup the database rejects.', ['domain' => $key, 'exception' => $e::class]);
                }
                unset($this->pending[$key]);
            }
        }

        return true;
    }

    /**
     * @param  array<string, array<string, ?string>>  $rows
     */
    private function upsert(array $rows): void
    {
        DomainLookup::query()->upsert(array_values($rows), ['domain'], ['source', 'status', 'expires_at', 'registrar', 'error', 'checked_at']);
    }

    private function writeFailed(Throwable $e): bool
    {
        Log::warning('Domain lookups could not be cached.', ['pending' => count($this->pending), 'exception' => $e::class]);

        return false;
    }

    public function pendingWrites(): int
    {
        return count($this->pending);
    }

    /**
     * The host whose registration a monitor reports on, if any.
     */
    public static function hostOf(MonitorSpec $spec): ?string
    {
        $config = $spec->config;

        if ($spec->type === MonitorType::Domain) {
            return is_string($config['domain'] ?? null) ? $config['domain'] : null;
        }
        if ($spec->type === MonitorType::Http && ($config['domain_expiry'] ?? true) === true && is_string($config['url'] ?? null)) {
            $host = parse_url($config['url'], PHP_URL_HOST);

            return is_string($host) ? trim($host, '[]') : null;
        }

        return null;
    }

    private function fresh(string $key): ?DomainExpiry
    {
        $cached = $this->memory[$key] ?? null;
        if ($cached === null || isset($this->inflight[$key])) {
            return null;
        }

        $factor = $this->ttlFactor[$key] ?? 1.0;

        return $cached->isFreshAt($this->clock->now(), (int) round(self::TTL_SECONDS * $factor), (int) round(self::FAILURE_TTL_SECONDS * $factor))
            ? $cached
            : null;
    }

    /**
     * @return Future<DomainExpiry>
     */
    private function startLookup(string $domain): Future
    {
        return $this->singleFlight($domain, function () use ($domain): DomainExpiry {
            $expiry = $this->query($domain);
            $this->remember($expiry);

            return $expiry;
        });
    }

    /**
     * @param  Closure(): DomainExpiry  $work
     * @return Future<DomainExpiry>
     */
    private function singleFlight(string $key, Closure $work): Future
    {
        return $this->inflight[$key] ??= async(function () use ($key, $work): DomainExpiry {
            try {
                return $work();
            } finally {
                unset($this->inflight[$key]);
            }
        });
    }

    /**
     * Never throws: every failure becomes an error answer.
     */
    private function query(string $domain): DomainExpiry
    {
        $tld = self::tld($domain);
        $base = $this->bootstrap->baseFor($tld);
        $source = $base === null ? DomainExpiry::SOURCE_WHOIS : DomainExpiry::SOURCE_RDAP;

        try {
            return $base !== null ? $this->rdapLookup($domain, $base) : $this->whoisLookup($domain, $tld);
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

    private function rdapLookup(string $domain, string $base): DomainExpiry
    {
        $host = parse_url($base, PHP_URL_HOST);

        return $this->limited(
            is_string($host) ? $host : $base,
            fn (Cancellation $deadline): DomainExpiry => $this->rdap->lookup($domain, $base, $this->lookupTimeout, $deadline, $this->clock->now()),
        );
    }

    private function whoisLookup(string $domain, string $tld): DomainExpiry
    {
        [$server, $authoritative] = $this->whoisServer($tld);
        $answer = $this->limited(self::serverHost($server), fn (Cancellation $deadline): string => $this->whois->query($server, $domain, $deadline));
        $now = $this->clock->now();

        if (trim($answer) === '') {
            return DomainExpiry::error($domain, DomainExpiry::SOURCE_WHOIS, 'Empty WHOIS response', $now);
        }

        $record = WhoisParser::parse($answer);
        $status = match (true) {
            $record->expiresAt !== null => DomainExpiry::STATUS_OK,
            // Only a server known to serve the TLD can state that a domain does not exist.
            $record->notFound && $authoritative => DomainExpiry::STATUS_NOT_FOUND,
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
     * The configured server, else the one IANA refers to, else the registry's conventional `whois.nic.{tld}`,
     * and whether the server is known to serve the TLD (configured or referred, not guessed). Discovery is
     * shared by concurrent lookups and cached like them: a referred server as `ok`, a guess as `unknown`.
     *
     * @return array{0: string, 1: bool}
     */
    private function whoisServer(string $tld): array
    {
        if (isset($this->whoisServers[$tld])) {
            return [$this->whoisServers[$tld], true];
        }

        $key = self::WHOIS_KEY_PREFIX.$tld;
        $discovery = $this->fresh($key);
        if ($discovery === null || $discovery->registrar === null || ! Hostname::isHostname($discovery->registrar, 2)) {
            $discovery = $this->singleFlight($key, function () use ($key, $tld): DomainExpiry {
                $answer = $this->limited($this->ianaWhois, fn (Cancellation $deadline): string => $this->whois->query($this->ianaWhois, $tld, $deadline));
                $referred = WhoisParser::referral($answer);
                $discovery = new DomainExpiry(
                    $key,
                    DomainExpiry::SOURCE_WHOIS,
                    $referred === null ? DomainExpiry::STATUS_UNKNOWN : DomainExpiry::STATUS_OK,
                    null,
                    $referred ?? "whois.nic.{$tld}",
                    null,
                    $this->clock->now(),
                );
                $this->remember($discovery);

                return $discovery;
            })->await();
        }

        return [(string) $discovery->registrar, $discovery->status === DomainExpiry::STATUS_OK];
    }

    /**
     * Runs a registry query once one of the server's slots is free; its deadline starts with the query.
     *
     * @template T
     *
     * @param  Closure(Cancellation): T  $query
     * @return T
     */
    private function limited(string $server, Closure $query): mixed
    {
        $lock = $this->registries->acquire(strtolower($server));

        try {
            return $query(new TimeoutCancellation($this->lookupTimeout));
        } finally {
            $lock->release();
        }
    }

    private function remember(DomainExpiry $expiry): void
    {
        $this->keep($expiry);

        unset($this->pending[$expiry->domain]);
        $this->pending[$expiry->domain] = $expiry->toRow();
        if (count($this->pending) > self::MAX_PENDING_WRITES) {
            array_shift($this->pending);
        }
    }

    private function keep(DomainExpiry $expiry): void
    {
        $this->memory[$expiry->domain] = $expiry;
        $this->ttlFactor[$expiry->domain] = ($this->jitter)();
    }

    private static function tld(string $domain): string
    {
        return substr($domain, (int) strrpos($domain, '.') + 1);
    }

    private static function serverHost(string $server): string
    {
        return strtolower((string) preg_replace('/:\d+$/', '', $server));
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
