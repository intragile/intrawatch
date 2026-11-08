<?php

namespace App\Status\Pages;

use App\Models\StatusPage;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Version stamps in the file cache that cached public pages validate against. A page version moves when the
 * page itself or something it shows is edited; the workspace stamp moves when a monitor's status changes.
 * Everything here is file I/O only, so it is safe in the worker and never touches the database.
 *
 * The writes run after the data they describe has committed. A failing write (a full or unwritable cache
 * directory) is reported and swallowed rather than failing a request whose data is already stored; the cache
 * TTL then bounds how long a page shows the earlier content.
 */
final class PageStamps
{
    public static function slugKey(string $slug): string
    {
        return 'status:slug:'.$slug;
    }

    public static function domainKey(string $host): string
    {
        return 'status:domain:'.$host;
    }

    /**
     * The map of every published page's custom domain.
     */
    public static function domainsKey(): string
    {
        return 'status:domains';
    }

    /**
     * The last domain map read from the database. Page writes leave it in place, so a database outage right
     * after a write still knows which hosts belong to status pages.
     */
    public static function domainsLastGoodKey(): string
    {
        return 'status:domains:last';
    }

    public static function htmlKey(int $pageId): string
    {
        return 'status:html:'.$pageId;
    }

    /**
     * Entry key of a non-HTML rendering of a page (see `PageCache::VARIANTS`).
     */
    public static function variantKey(int $pageId, string $variant): string
    {
        return 'status:'.$variant.':'.$pageId;
    }

    public static function variantLastGoodKey(int $pageId, string $variant): string
    {
        return 'status:last:'.$variant.':'.$pageId;
    }

    public static function lastGoodKey(int $pageId): string
    {
        return 'status:last:'.$pageId;
    }

    public function pageVersion(int $pageId): string
    {
        return $this->current('status:page:'.$pageId.':v');
    }

    public function bumpPage(int ...$pageIds): void
    {
        $this->attempt(function () use ($pageIds): void {
            foreach (array_unique($pageIds) as $pageId) {
                $this->store()->forever('status:page:'.$pageId.':v', self::token());
            }
        });
    }

    public function workspaceStamp(int $workspaceId): string
    {
        return $this->current('status:ws:'.$workspaceId.':stamp');
    }

    public function bumpWorkspace(int $workspaceId): void
    {
        $this->attempt(fn () => $this->store()->forever('status:ws:'.$workspaceId.':stamp', self::token()));
    }

    /**
     * Drops the page's rendered entries and resolver keys for its current slug and domain, and moves its version.
     */
    public function forgetPage(StatusPage $page): void
    {
        $this->forgetKeys($page->id, $page->slug, $page->custom_domain);
    }

    /**
     * Same as `forgetPage` for a slug and domain the page no longer has.
     */
    public function forgetKeys(int $pageId, ?string $slug, ?string $domain): void
    {
        $this->attempt(function () use ($pageId, $slug, $domain): void {
            $store = $this->store();
            $this->dropRenderings($store, $pageId);
            if ($slug !== null && $slug !== '') {
                $store->forget(self::slugKey($slug));
            }
            if ($domain !== null && $domain !== '') {
                $store->forget(self::domainKey($domain));
            }
            $store->forget(self::domainsKey());
        });
        $this->bumpPage($pageId);
    }

    /**
     * Drops every rendering of the pages, last good copies included, and moves their versions; slug and domain
     * resolution is kept. For edits that remove content: an outage before the next render must answer without
     * a copy rather than bring the removed content back.
     */
    public function forgetRenderings(int ...$pageIds): void
    {
        $pageIds = array_values(array_unique($pageIds));
        $this->attempt(function () use ($pageIds): void {
            $store = $this->store();
            foreach ($pageIds as $pageId) {
                $this->dropRenderings($store, $pageId);
            }
        });
        $this->bumpPage(...$pageIds);
    }

    private function dropRenderings(Repository $store, int $pageId): void
    {
        $store->forget(self::htmlKey($pageId));
        $store->forget(self::lastGoodKey($pageId));
        foreach (PageCache::VARIANTS as $variant) {
            $store->forget(self::variantKey($pageId, $variant));
            $store->forget(self::variantLastGoodKey($pageId, $variant));
        }
    }

    /**
     * @param  Closure(): mixed  $write
     */
    private function attempt(Closure $write): void
    {
        try {
            $write();
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function current(string $key): string
    {
        $store = $this->store();
        $value = $store->get($key);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $value = self::token();
        // add() keeps a concurrent writer's value, so two processes agree on one stamp.
        $store->add($key, $value);
        $stored = $store->get($key);

        return is_string($stored) && $stored !== '' ? $stored : $value;
    }

    private function store(): Repository
    {
        return Cache::store('status_pages');
    }

    private static function token(): string
    {
        return sprintf('%.6f', microtime(true)).'-'.Str::random(8);
    }
}
