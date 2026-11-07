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
 * from one cached map of every published page's domain, refreshed every 60 s, so an unknown host costs one
 * file read and never a query or a cache entry of its own. Page writes drop both through `PageStamps`.
 *
 * Known entries and the domain map are kept for the last-good period: while the database is down they still
 * resolve, so the last good copy of a page can be served, and the database is retried at most once every
 * 30 s per entry instead of on every request. A failure also sets the shared `DatabaseOutage` marker, which
 * keeps every other uncached lookup off the database for 30 s. Unpublished pages resolve exactly like missing ones.
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
            $this->failed();
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
        $host = self::normalizeHost($host);
        if ($host === '' || strlen($host) > 253) {
            return null;
        }

        $page = $this->domains()[$host] ?? null;

        return $page === null ? null : ['id' => $page[0], 'workspace_id' => $page[1]];
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
     * Published pages by custom domain.
     *
     * @return array<string, array{int, int}>
     */
    private function domains(): array
    {
        $now = self::now();
        $known = self::map($this->store()->get(PageStamps::domainsKey()));
        if ($known !== null && $now < $known['fresh_until']) {
            return $known['domains'];
        }

        try {
            $this->outage->guard();
            $rows = DB::table('status_pages')
                ->whereNotNull('custom_domain')
                ->where('published', true)
                ->get(['id', 'workspace_id', 'custom_domain']);
        } catch (QueryException|PDOException $e) {
            $this->failed();
            if ($known === null) {
                throw $e;
            }
            $this->putMap($known['domains'], $now + self::RETRY_SECONDS);

            return $known['domains'];
        }

        $domains = [];
        foreach ($rows as $row) {
            if (is_string($row->custom_domain) && $row->custom_domain !== '') {
                $domains[strtolower($row->custom_domain)] = [self::int($row->id), self::int($row->workspace_id)];
            }
        }
        $this->putMap($domains, $now + self::DOMAINS_SECONDS);

        return $domains;
    }

    /**
     * Sets the shared marker on a real failure; one raised by the marker itself leaves its expiry alone.
     */
    private function failed(): void
    {
        if (! $this->outage->active()) {
            $this->outage->record();
        }
    }

    /**
     * @param  array<string, array{int, int}>  $domains
     */
    private function putMap(array $domains, int $freshUntil): void
    {
        $this->store()->put(PageStamps::domainsKey(), ['domains' => $domains, 'fresh_until' => $freshUntil], $this->keepSeconds());
    }

    private function putFound(string $key, int $id, int $workspaceId, int $freshUntil): void
    {
        $this->store()->put($key, ['id' => $id, 'workspace_id' => $workspaceId, 'found' => true, 'fresh_until' => $freshUntil], $this->keepSeconds());
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
     * @return array{domains: array<string, array{int, int}>, fresh_until: int}|null
     */
    private static function map(mixed $cached): ?array
    {
        if (! is_array($cached) || ! is_array($cached['domains'] ?? null) || ! is_int($cached['fresh_until'] ?? null)) {
            return null;
        }
        $domains = [];
        foreach ($cached['domains'] as $host => $page) {
            if (is_string($host) && is_array($page) && is_int($page[0] ?? null) && is_int($page[1] ?? null)) {
                $domains[$host] = [$page[0], $page[1]];
            }
        }

        return ['domains' => $domains, 'fresh_until' => $cached['fresh_until']];
    }

    private function keepSeconds(): int
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
