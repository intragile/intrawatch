<?php

namespace App\Status\Pages;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use LogicException;
use PDOException;

/**
 * Rendered public pages in the file cache.
 *
 * An entry is fresh while the page version and workspace stamp it was rendered under are still current and
 * it has not expired (the configured TTL, or earlier when the view itself expires at a maintenance boundary).
 * A fresh entry is served from the file cache alone, without touching the database. On a miss one process
 * renders under a lock while the others serve the last good copy; when the render fails because the database
 * does, the last good copy is served marked stale, kept for `status.cache.stale_seconds`.
 */
final class PageCache
{
    private const LOCK_SECONDS = 10;

    private const LOCK_POLL_MICROSECONDS = 100_000;

    /**
     * @param  int  $lockWaitSeconds  How long a request without a copy to serve waits for another render.
     */
    public function __construct(
        private readonly PageStamps $stamps,
        private readonly int $lockWaitSeconds = 3,
    ) {}

    /**
     * @param  Closure(): PageView  $build
     * @param  Closure(PageView): string  $view
     * @param  string  $variant  Distinguishes renderings of the same page (the HTML page, a feed, …).
     *
     * @throws PageUnavailable when the database fails and no earlier copy exists
     */
    public function render(int $pageId, int $workspaceId, Closure $build, Closure $view, string $variant = 'html'): CachedPage
    {
        [$entryKey, $lastKey, $lockKey] = $this->keys($pageId, $variant);
        $store = $this->store();
        // Read before rendering: a stamp that moves while a render runs leaves the new entry already stale.
        $pageVersion = $this->stamps->pageVersion($pageId);
        $workspaceStamp = $this->stamps->workspaceStamp($workspaceId);

        $fresh = $this->fresh($store->get($entryKey), $pageVersion, $workspaceStamp);
        if ($fresh !== null) {
            return $fresh;
        }

        $lock = $this->lock($lockKey);
        if (! $lock->get()) {
            $last = self::entry($store->get($lastKey));
            if ($last !== null) {
                return new CachedPage($last['html'], $last['etag'], false);
            }
            $lock = $this->wait($lock);
        }

        try {
            // Another process may have rendered while this one waited for the lock.
            $fresh = $this->fresh($store->get($entryKey), $pageVersion, $workspaceStamp);
            if ($fresh !== null) {
                return $fresh;
            }

            return $this->renderFresh($entryKey, $lastKey, $pageVersion, $workspaceStamp, $build, $view);
        } finally {
            $lock?->release();
        }
    }

    /**
     * @param  Closure(): PageView  $build
     * @param  Closure(PageView): string  $view
     */
    private function renderFresh(string $entryKey, string $lastKey, string $pageVersion, string $workspaceStamp, Closure $build, Closure $view): CachedPage
    {
        $store = $this->store();
        try {
            $pageView = $build();
            $html = $view($pageView);
        } catch (QueryException|PDOException $e) {
            $last = self::entry($store->get($lastKey));
            if ($last === null) {
                throw PageUnavailable::because($e);
            }

            return new CachedPage($last['html'], $last['etag'], true);
        }

        $now = CarbonImmutable::now();
        $expiresAt = $now->addSeconds(max(1, config()->integer('status.cache.ttl_seconds')));
        if ($pageView->expiresAt !== null && $pageView->expiresAt->lessThan($expiresAt)) {
            $expiresAt = $pageView->expiresAt->max($now->addSecond());
        }

        $etag = '"'.hash('sha256', $html).'"';
        $entry = [
            'html' => $html,
            'etag' => $etag,
            'page_version' => $pageVersion,
            'workspace_stamp' => $workspaceStamp,
            'expires_at' => $expiresAt->getTimestamp(),
            'generated_at' => $now->getTimestamp(),
        ];
        $store->put($entryKey, $entry, max(1, $expiresAt->getTimestamp() - $now->getTimestamp()));
        $store->put($lastKey, $entry, max(1, config()->integer('status.cache.stale_seconds')));

        return new CachedPage($html, $etag, false);
    }

    /**
     * Waits for the lock; on timeout the caller renders anyway, without it. Timed with the monotonic clock, so a
     * frozen or travelling application clock cannot stretch the wait.
     */
    private function wait(Lock $lock): ?Lock
    {
        $deadline = hrtime(true) + $this->lockWaitSeconds * 1_000_000_000;
        do {
            usleep(self::LOCK_POLL_MICROSECONDS);
            if ($lock->get()) {
                return $lock;
            }
        } while (hrtime(true) < $deadline);

        return null;
    }

    private function fresh(mixed $cached, string $pageVersion, string $workspaceStamp): ?CachedPage
    {
        $entry = self::entry($cached);
        if ($entry === null
            || $entry['page_version'] !== $pageVersion
            || $entry['workspace_stamp'] !== $workspaceStamp
            || CarbonImmutable::now()->getTimestamp() >= $entry['expires_at']) {
            return null;
        }

        return new CachedPage($entry['html'], $entry['etag'], false);
    }

    /**
     * @return array{html: string, etag: string, page_version: string, workspace_stamp: string, expires_at: int}|null
     */
    private static function entry(mixed $cached): ?array
    {
        if (! is_array($cached)
            || ! is_string($cached['html'] ?? null)
            || ! is_string($cached['etag'] ?? null)
            || ! is_string($cached['page_version'] ?? null)
            || ! is_string($cached['workspace_stamp'] ?? null)
            || ! is_int($cached['expires_at'] ?? null)) {
            return null;
        }

        return [
            'html' => $cached['html'],
            'etag' => $cached['etag'],
            'page_version' => $cached['page_version'],
            'workspace_stamp' => $cached['workspace_stamp'],
            'expires_at' => $cached['expires_at'],
        ];
    }

    /**
     * @return array{string, string, string} Entry, last good copy and render lock keys.
     */
    private function keys(int $pageId, string $variant): array
    {
        if ($variant === 'html') {
            return [PageStamps::htmlKey($pageId), PageStamps::lastGoodKey($pageId), 'status:render:'.$pageId];
        }

        return ['status:'.$variant.':'.$pageId, 'status:last:'.$variant.':'.$pageId, 'status:render:'.$pageId.':'.$variant];
    }

    /**
     * The lock a render of the page holds; another holder makes requests serve the last good copy.
     */
    public function renderLock(int $pageId, string $variant = 'html'): Lock
    {
        return $this->lock($this->keys($pageId, $variant)[2]);
    }

    private function lock(string $key): Lock
    {
        $store = $this->store()->getStore();
        if (! $store instanceof LockProvider) {
            throw new LogicException('The status_pages cache store must support locks.');
        }

        return $store->lock($key, self::LOCK_SECONDS);
    }

    private function store(): Repository
    {
        return Cache::store('status_pages');
    }
}
