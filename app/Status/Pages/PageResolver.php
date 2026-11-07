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
 * A found page is re-checked against the database every 300 s, a miss every 30 s. The found entry itself is
 * kept for the last-good period, so while the database is down a known slug or domain still resolves and its
 * last good copy can be served. Unpublished pages resolve exactly like missing ones.
 */
final class PageResolver
{
    private const FOUND_SECONDS = 300;

    private const MISSING_SECONDS = 30;

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

        return $this->resolve(PageStamps::slugKey($slug), 'slug', $slug);
    }

    /**
     * @return array{id: int, workspace_id: int}|null
     *
     * @throws QueryException|PDOException when the database fails and nothing is known about the host
     */
    public function byDomain(string $host): ?array
    {
        $host = self::normalizeHost($host);
        if ($host === '' || strlen($host) > 253) {
            return null;
        }

        return $this->resolve(PageStamps::domainKey($host), 'custom_domain', $host);
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
     * @return array{id: int, workspace_id: int}|null
     */
    private function resolve(string $key, string $column, string $value): ?array
    {
        $store = $this->store();
        $cached = $store->get($key);
        $now = CarbonImmutable::now()->getTimestamp();
        if (is_array($cached) && ($cached['found'] ?? null) === false) {
            return null;
        }
        $known = self::found($cached);
        if ($known !== null && $now < $known['fresh_until']) {
            return ['id' => $known['id'], 'workspace_id' => $known['workspace_id']];
        }

        try {
            $row = DB::table('status_pages')
                ->where($column, $value)
                ->where('published', true)
                ->first(['id', 'workspace_id']);
        } catch (QueryException|PDOException $e) {
            if ($known !== null) {
                return ['id' => $known['id'], 'workspace_id' => $known['workspace_id']];
            }

            throw $e;
        }

        if ($row === null) {
            $store->put($key, ['found' => false], self::MISSING_SECONDS);

            return null;
        }

        $page = ['id' => self::int($row->id), 'workspace_id' => self::int($row->workspace_id)];
        $store->put($key, [...$page, 'found' => true, 'fresh_until' => $now + self::FOUND_SECONDS], max(self::FOUND_SECONDS, config()->integer('status.cache.stale_seconds')));

        return $page;
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

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    private function store(): Repository
    {
        return Cache::store('status_pages');
    }
}
