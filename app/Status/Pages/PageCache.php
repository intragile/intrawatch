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
 * does, the last good copy is served marked stale, kept for `status.cache.stale_seconds`, and the database is
 * not tried again for 30 s. A failure also sets the shared `DatabaseOutage` marker; while it is set, no render
 * of any page is attempted.
 */
final class PageCache
{
    /** Every rendering of a page besides the HTML one; page edits drop all of them. */
    public const VARIANTS = ['atom', 'rss', 'summary', 'badges'];

    private const LOCK_SECONDS = 10;

    private const LOCK_POLL_MICROSECONDS = 100_000;

    /** After a failed render, how long the stale copy is served before the database is tried again. */
    private const RETRY_SECONDS = 30;

    /**
     * @param  int  $lockWaitSeconds  How long a request without a copy to serve waits for another render.
     */
    public function __construct(
        private readonly PageStamps $stamps,
        private readonly DatabaseOutage $outage,
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
                // Another process is rendering; a copy older than one more freshness period counts as stale.
                $overdue = CarbonImmutable::now()->getTimestamp() - $last['expires_at'] > self::ttl();

                return new CachedPage($last['html'], $last['etag'], $overdue);
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
            $this->outage->guard();
            $pageView = $build();
            $html = $view($pageView);
        } catch (QueryException|PDOException $e) {
            if (! $this->outage->active()) {
                $this->outage->record();
            }
            $last = self::entry($store->get($lastKey));
            if ($last === null) {
                throw PageUnavailable::because($e);
            }
            // Serve the copy, marked stale, without retrying the database for a while.
            $store->put($entryKey, [
                ...$last,
                'page_version' => $pageVersion,
                'workspace_stamp' => $workspaceStamp,
                'expires_at' => CarbonImmutable::now()->getTimestamp() + self::RETRY_SECONDS,
                'stale' => true,
            ], self::RETRY_SECONDS);

            return new CachedPage($last['html'], $last['etag'], true);
        }

        $now = CarbonImmutable::now();
        $expiresAt = $now->addSeconds(self::ttl());
        if ($pageView->expiresAt !== null && $pageView->expiresAt->lessThan($expiresAt)) {
            $expiresAt = $pageView->expiresAt->max($now->addSecond());
        }

        $etag = self::etag($html);
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
     * Strong entity tag of a body. Segments between `<!--volatile-->` and `<!--/volatile-->` (such as a
     * "last updated" time) are left out, so a re-render with unchanged content keeps its tag and clients
     * revalidating it get a 304.
     */
    public static function etag(string $body): string
    {
        $stable = preg_replace('#<!--volatile-->.*?<!--/volatile-->#s', '', $body);

        return '"'.hash('sha256', $stable ?? $body).'"';
    }

    private static function ttl(): int
    {
        return max(1, config()->integer('status.cache.ttl_seconds'));
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

        return new CachedPage($entry['html'], $entry['etag'], $entry['stale']);
    }

    /**
     * @return array{html: string, etag: string, page_version: string, workspace_stamp: string, expires_at: int, stale: bool}|null
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
            'stale' => ($cached['stale'] ?? false) === true,
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

        return [PageStamps::variantKey($pageId, $variant), PageStamps::variantLastGoodKey($pageId, $variant), 'status:render:'.$pageId.':'.$variant];
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
