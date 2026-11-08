<?php

namespace App\Status\Pages;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * Finds the published page behind a slug or a custom domain, from the file cache first.
 *
 * A found slug is re-checked against the database every 300 s, a missing one every 30 s. Custom domains come
 * from one cached map of every page's domain (published or not, see `hostState`), refreshed every 60 s, so an unknown host costs one
 * file read and never a query or a cache entry of its own. Page writes drop both through `PageStamps`.
 *
 * Known entries and the domain map are kept for the last-good period: while the database is down they still
 * resolve, so the last good copy of a page can be served, and the database is retried at most once every
 * 30 s per entry instead of on every request. A failure to reach the database also sets the shared
 * `DatabaseOutage` marker, which keeps every other uncached lookup off the database for 30 s. Unpublished pages resolve exactly like missing ones.
 *
 * A page write marks the page unpublished in the last-good domain map: its hosts stay reserved, but an outage
 * before the next successful read does not serve a state the write may have changed. A read that overlaps a
 * write (seen through `PageStamps::domainsWrittenKey`) is returned but not cached. A cache store that rejects
 * every write keeps the earlier map until its TTL runs out, as it does for every other entry here.
 */
final class PageResolver
{
    private const FOUND_SECONDS = 300;

    private const MISSING_SECONDS = 30;

    private const DOMAINS_SECONDS = 60;

    private const RETRY_SECONDS = 30;

    public function __construct(private readonly DatabaseOutage $outage) {}

    /**
     * @return array{id: int, workspace_id: int}|null
     *
     * @throws QueryException|PDOException when the database fails and nothing is known about the slug
     */
    public function bySlug(string $slug): ?array
    {
        if ($slug === '' || strlen($slug) > 190) {
            return null;
        }

        $key = PageStamps::slugKey($slug);
        $store = $this->store();
        $cached = $store->get($key);
        if (is_array($cached) && ($cached['found'] ?? null) === false) {
            return null;
        }
        $now = self::now();
        $known = self::found($cached);
        if ($known !== null && $now < $known['fresh_until']) {
            return ['id' => $known['id'], 'workspace_id' => $known['workspace_id']];
        }

        try {
            $this->outage->guard();
            $row = DB::table('status_pages')
                ->where('slug', $slug)
                ->where('published', true)
                ->first(['id', 'workspace_id']);
        } catch (QueryException|PDOException $e) {
            $this->failed($e);
            if ($known === null) {
                throw $e;
            }
            // Back off: the next attempt is due in 30 s, not on the next request.
            $this->putFound($key, $known['id'], $known['workspace_id'], $now + self::RETRY_SECONDS);

            return ['id' => $known['id'], 'workspace_id' => $known['workspace_id']];
        }

        if ($row === null) {
            $store->put($key, ['found' => false], self::MISSING_SECONDS);

            return null;
        }

        $page = ['id' => self::int($row->id), 'workspace_id' => self::int($row->workspace_id)];
        $this->putFound($key, $page['id'], $page['workspace_id'], $now + self::FOUND_SECONDS);

        return $page;
    }

    /**
     * @return array{id: int, workspace_id: int}|null
     *
     * @throws QueryException|PDOException when the database fails and no domain map is known
     */
    public function byDomain(string $host): ?array
    {
        $state = $this->hostState($host);

        return $state === null || ! $state['published'] ? null : ['id' => $state['id'], 'workspace_id' => $state['workspace_id']];
    }

    /**
     * The page that claims the host as its custom domain, published or not.
     *
     * @return array{id: int, workspace_id: int, published: bool}|null
     *
     * @throws QueryException|PDOException when the database fails and no domain map is known
     */
    public function hostState(string $host): ?array
    {
        $host = self::normalizeHost($host);
        if ($host === '' || strlen($host) > 253) {
            return null;
        }

        $page = $this->domains()[$host] ?? null;

        return $page === null ? null : ['id' => $page[0], 'workspace_id' => $page[1], 'published' => $page[2]];
    }

    /**
     * Whether the installation allows custom domains at all (`status.custom_domains.allowed` is not empty).
     */
    public static function customDomainsEnabled(): bool
    {
        return self::customDomainPatterns() !== [];
    }

    /**
     * Whether the installation's allowlist covers the host. An entry is an exact host or `*.example.com`,
     * which matches any host below example.com but not example.com itself. The system (command line) is not
     * bound by the list; callers decide when it applies.
     */
    public static function allowsCustomDomain(string $host): bool
    {
        $host = self::normalizeHost($host);
        if ($host === '') {
            return false;
        }
        foreach (self::customDomainPatterns() as $pattern) {
            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 1);
                if (strlen($host) > strlen($suffix) && str_ends_with($host, $suffix)) {
                    return true;
                }
            } elseif ($pattern === $host) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function customDomainPatterns(): array
    {
        $patterns = [];
        foreach ((array) config('status.custom_domains.allowed', []) as $pattern) {
            if (is_string($pattern) && ($pattern = rtrim(strtolower(trim($pattern)), '.')) !== '') {
                $patterns[] = $pattern;
            }
        }

        return $patterns;
    }

    /**
     * Lower-case host without a port or a trailing dot, the form custom domains are stored in.
     */
    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, '[')) {
            // An IPv6 literal is never a custom domain; keep it intact without its port.
            $end = strpos($host, ']');

            return $end === false ? $host : substr($host, 0, $end + 1);
        }
        $colon = strrpos($host, ':');
        if ($colon !== false) {
            $host = substr($host, 0, $colon);
        }

        return rtrim($host, '.');
    }

    /**
     * Pages by custom domain, published or not.
     *
     * @return array<string, array{int, int, bool}>
     */
    private function domains(): array
    {
        $now = self::now();
        $store = $this->store();
        $known = self::map($store->get(PageStamps::domainsKey()));
        if ($known !== null && $now < $known['fresh_until']) {
            return $known['domains'];
        }

        $written = $store->get(PageStamps::domainsWrittenKey());
        try {
            $this->outage->guard();
            $rows = DB::table('status_pages')
                ->whereNotNull('custom_domain')
                ->get(['id', 'workspace_id', 'custom_domain', 'published']);
        } catch (QueryException|PDOException $e) {
            $this->failed($e);
            if ($store->get(PageStamps::domainsWrittenKey()) !== $written) {
                // A page write landed after the maps were read; only the last-good map, which the write has
                // already withdrawn the page from, is current. It is served but not copied into the fresh map.
                $known = self::map($store->get(PageStamps::domainsLastGoodKey()));
                if ($known === null) {
                    throw $e;
                }

                return $known['domains'];
            }
            // A page write drops the fresh map; the last good one still knows the hosts.
            $known ??= self::map($store->get(PageStamps::domainsLastGoodKey()));
            if ($known === null) {
                throw $e;
            }
            $this->putMap($known['domains'], $now + self::RETRY_SECONDS);
            if ($store->get(PageStamps::domainsWrittenKey()) !== $written) {
                // The write landed between the check and the put; the copy must not outlive it.
                $store->forget(PageStamps::domainsKey());
            }

            return $known['domains'];
        }

        $domains = [];
        foreach ($rows as $row) {
            if (is_string($row->custom_domain) && $row->custom_domain !== '') {
                $domains[strtolower($row->custom_domain)] = [self::int($row->id), self::int($row->workspace_id), (bool) $row->published];
            }
        }
        if ($store->get(PageStamps::domainsWrittenKey()) !== $written) {
            // A page write landed during the query; the rows may predate it, so they are not kept.
            return $domains;
        }
        $this->putMap($domains, $now + self::DOMAINS_SECONDS);
        $store->put(PageStamps::domainsLastGoodKey(), ['domains' => $domains, 'fresh_until' => $now], self::keepSeconds());
        if ($store->get(PageStamps::domainsWrittenKey()) !== $written) {
            // The write landed between the check and the puts; which page it touched is unknown, so drop both.
            $store->forget(PageStamps::domainsKey());
            $store->forget(PageStamps::domainsLastGoodKey());
        }

        return $domains;
    }

    /**
     * Marks the page unpublished in the last-good domain map, keeping its hosts reserved. Called on every page
     * write, so an outage right after it cannot serve the page from a copy taken before the write.
     */
    public static function withdrawFromLastGood(Repository $store, int $pageId): void
    {
        $known = self::map($store->get(PageStamps::domainsLastGoodKey()));
        if ($known === null) {
            return;
        }
        $changed = false;
        $domains = $known['domains'];
        foreach ($domains as $host => [$id, $workspaceId, $published]) {
            if ($id === $pageId && $published) {
                $domains[$host] = [$id, $workspaceId, false];
                $changed = true;
            }
        }
        if ($changed) {
            $store->put(PageStamps::domainsLastGoodKey(), ['domains' => $domains, 'fresh_until' => $known['fresh_until']], self::keepSeconds());
        }
    }

    /**
     * Sets the shared marker when the database cannot be reached; one raised by the marker itself leaves its
     * expiry alone. Other errors only back off the entry that failed.
     */
    private function failed(QueryException|PDOException $e): void
    {
        if (! $this->outage->active()) {
            $this->outage->recordIfUnreachable($e);
        }
    }

    /**
     * @param  array<string, array{int, int, bool}>  $domains
     */
    private function putMap(array $domains, int $freshUntil): void
    {
        $this->store()->put(PageStamps::domainsKey(), ['domains' => $domains, 'fresh_until' => $freshUntil], self::keepSeconds());
    }

    private function putFound(string $key, int $id, int $workspaceId, int $freshUntil): void
    {
        $this->store()->put($key, ['id' => $id, 'workspace_id' => $workspaceId, 'found' => true, 'fresh_until' => $freshUntil], self::keepSeconds());
    }

    /**
     * @return array{id: int, workspace_id: int, fresh_until: int}|null
     */
    private static function found(mixed $cached): ?array
    {
        if (! is_array($cached) || ($cached['found'] ?? null) !== true
            || ! is_int($cached['id'] ?? null) || ! is_int($cached['workspace_id'] ?? null) || ! is_int($cached['fresh_until'] ?? null)) {
            return null;
        }

        return ['id' => $cached['id'], 'workspace_id' => $cached['workspace_id'], 'fresh_until' => $cached['fresh_until']];
    }

    /**
     * A map written before unpublished pages were included has two-element entries; they are published.
     *
     * @return array{domains: array<string, array{int, int, bool}>, fresh_until: int}|null
     */
    private static function map(mixed $cached): ?array
    {
        if (! is_array($cached) || ! is_array($cached['domains'] ?? null) || ! is_int($cached['fresh_until'] ?? null)) {
            return null;
        }
        $domains = [];
        foreach ($cached['domains'] as $host => $page) {
            if (is_string($host) && is_array($page) && is_int($page[0] ?? null) && is_int($page[1] ?? null)) {
                $domains[$host] = [$page[0], $page[1], is_bool($page[2] ?? null) ? $page[2] : true];
            }
        }

        return ['domains' => $domains, 'fresh_until' => $cached['fresh_until']];
    }

    private static function keepSeconds(): int
    {
        return max(self::FOUND_SECONDS, config()->integer('status.cache.stale_seconds'));
    }

    private static function now(): int
    {
        return CarbonImmutable::now()->getTimestamp();
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    private function store(): Repository
    {
        return Cache::store('status_pages');
    }
}
