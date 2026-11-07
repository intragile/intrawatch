<?php

namespace Tests\Feature\Status;

use App\Enums\DisplayStatus;
use App\Status\Pages\DatabaseOutage;
use App\Status\Pages\PageCache;
use App\Status\Pages\PageStamps;
use App\Status\Pages\PageUnavailable;
use App\Status\Pages\PageView;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class PageCacheTest extends TestCase
{
    private const PAGE = 7;

    private const WORKSPACE = 3;

    private PageStamps $stamps;

    private int $builds = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-07 12:00:00', 'UTC'));
        $this->stamps = app(PageStamps::class);
    }

    public function test_hit_does_not_build_again(): void
    {
        $cache = $this->cache();

        $first = $cache->render(self::PAGE, self::WORKSPACE, $this->build('one'), $this->renderer());
        $second = $cache->render(self::PAGE, self::WORKSPACE, $this->build('two'), $this->renderer());

        $this->assertSame(1, $this->builds);
        $this->assertSame('<p>one</p>', $first->html);
        $this->assertSame($first->html, $second->html);
        $this->assertSame('"'.hash('sha256', '<p>one</p>').'"', $second->etag);
        $this->assertFalse($second->stale);
    }

    public function test_page_and_workspace_stamps_invalidate(): void
    {
        $cache = $this->cache();
        $cache->render(self::PAGE, self::WORKSPACE, $this->build('one'), $this->renderer());

        $this->stamps->bumpPage(self::PAGE);
        $this->assertSame('<p>two</p>', $cache->render(self::PAGE, self::WORKSPACE, $this->build('two'), $this->renderer())->html);

        $this->stamps->bumpWorkspace(self::WORKSPACE + 1);
        $this->assertSame('<p>two</p>', $cache->render(self::PAGE, self::WORKSPACE, $this->build('three'), $this->renderer())->html);

        $this->stamps->bumpWorkspace(self::WORKSPACE);
        $this->assertSame('<p>four</p>', $cache->render(self::PAGE, self::WORKSPACE, $this->build('four'), $this->renderer())->html);
        $this->assertSame(3, $this->builds);
    }

    public function test_entry_expires_at_the_earlier_of_ttl_and_view_expiry(): void
    {
        config(['status.cache.ttl_seconds' => 60]);
        $cache = $this->cache();
        $cache->render(self::PAGE, self::WORKSPACE, $this->build('one', CarbonImmutable::now()->addSeconds(20)), $this->renderer());

        $this->travel(19)->seconds();
        $this->assertSame('<p>one</p>', $cache->render(self::PAGE, self::WORKSPACE, $this->build('two'), $this->renderer())->html);

        $this->travel(2)->seconds();
        $this->assertSame('<p>two</p>', $cache->render(self::PAGE, self::WORKSPACE, $this->build('two'), $this->renderer())->html);

        $this->travel(59)->seconds();
        $this->assertSame('<p>two</p>', $cache->render(self::PAGE, self::WORKSPACE, $this->build('three'), $this->renderer())->html);
        $this->travel(2)->seconds();
        $this->assertSame('<p>four</p>', $cache->render(self::PAGE, self::WORKSPACE, $this->build('four'), $this->renderer())->html);
    }

    public function test_query_failure_serves_last_good_copy_marked_stale(): void
    {
        $cache = $this->cache();
        $good = $cache->render(self::PAGE, self::WORKSPACE, $this->build('one'), $this->renderer());
        $this->travel(2)->minutes();

        $stale = $cache->render(self::PAGE, self::WORKSPACE, $this->failing(new PDOException('gone')), $this->renderer());
        $this->assertTrue($stale->stale);
        $this->assertSame($good->html, $stale->html);
        $this->assertSame($good->etag, $stale->etag);

        $stale = $cache->render(self::PAGE, self::WORKSPACE, $this->failing(new QueryException('sqlite', 'select 1', [], new PDOException('gone'))), $this->renderer());
        $this->assertTrue($stale->stale);

        // The database is retried 30 s after the failure; once it is back a fresh render replaces the copy.
        $this->travel(31)->seconds();
        $fresh = $cache->render(self::PAGE, self::WORKSPACE, $this->build('two'), $this->renderer());
        $this->assertFalse($fresh->stale);
        $this->assertSame('<p>two</p>', $fresh->html);
    }

    public function test_last_good_copy_is_kept_for_stale_seconds(): void
    {
        config(['status.cache.stale_seconds' => 3600]);
        $cache = $this->cache();
        $cache->render(self::PAGE, self::WORKSPACE, $this->build('one'), $this->renderer());

        $this->travel(3599)->seconds();
        $this->assertTrue($cache->render(self::PAGE, self::WORKSPACE, $this->failing(new PDOException('gone')), $this->renderer())->stale);

        // Past the retry pause and the copy's lifetime there is nothing left to serve.
        $this->travel(32)->seconds();
        $this->expectException(PageUnavailable::class);
        $cache->render(self::PAGE, self::WORKSPACE, $this->failing(new PDOException('gone')), $this->renderer());
    }

    public function test_query_failure_without_a_copy_is_unavailable(): void
    {
        $this->expectException(PageUnavailable::class);
        $this->cache()->render(self::PAGE, self::WORKSPACE, $this->failing(new PDOException('gone')), $this->renderer());
    }

    public function test_other_failures_are_not_hidden(): void
    {
        $cache = $this->cache();
        $cache->render(self::PAGE, self::WORKSPACE, $this->build('one'), $this->renderer());
        $this->travel(2)->minutes();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bug');
        $cache->render(self::PAGE, self::WORKSPACE, $this->failing(new RuntimeException('bug')), $this->renderer());
    }

    public function test_failed_render_is_not_retried_for_thirty_seconds(): void
    {
        $cache = $this->cache();
        $cache->render(self::PAGE, self::WORKSPACE, $this->build('one'), $this->renderer());
        $this->travel(2)->minutes();

        $attempts = 0;
        $failing = function () use (&$attempts): never {
            $attempts++;
            throw new PDOException('gone');
        };
        $this->assertTrue($cache->render(self::PAGE, self::WORKSPACE, $failing, $this->renderer())->stale);
        $this->travel(29)->seconds();
        $this->assertTrue($cache->render(self::PAGE, self::WORKSPACE, $failing, $this->renderer())->stale);
        $this->assertSame(1, $attempts);

        $this->travel(2)->seconds();
        $this->assertTrue($cache->render(self::PAGE, self::WORKSPACE, $failing, $this->renderer())->stale);
        $this->assertSame(2, $attempts);
    }

    public function test_overdue_copy_served_during_another_render_is_marked_stale(): void
    {
        config(['status.cache.ttl_seconds' => 60]);
        $cache = $this->cache();
        $cache->render(self::PAGE, self::WORKSPACE, $this->build('one'), $this->renderer());

        $served = function () use ($cache): bool {
            // Another process holds the render lock at this moment.
            $lock = $cache->renderLock(self::PAGE);
            $this->assertTrue($lock->get());
            try {
                return $cache->render(self::PAGE, self::WORKSPACE, $this->build('two'), $this->renderer())->stale;
            } finally {
                $lock->release();
            }
        };

        $this->travel(61)->seconds();
        $this->assertFalse($served());
        $this->travel(60)->seconds();
        $this->assertTrue($served());
        $this->assertSame(1, $this->builds);
    }

    public function test_etag_ignores_volatile_segments(): void
    {
        $this->assertSame(
            PageCache::etag('<p>a</p><!--volatile-->10:00<!--/volatile-->'),
            PageCache::etag('<p>a</p><!--volatile-->10:01<!--/volatile-->'),
        );
        $this->assertNotSame(PageCache::etag('<p>a</p>'), PageCache::etag('<p>b</p>'));
    }

    public function test_concurrent_misses_render_once(): void
    {
        $cache = $this->cache();
        $cache->render(self::PAGE, self::WORKSPACE, $this->build('one'), $this->renderer());
        $this->travel(2)->minutes();

        $lock = $this->cache()->renderLock(self::PAGE);
        $this->assertTrue($lock->get());
        try {
            $served = $cache->render(self::PAGE, self::WORKSPACE, $this->build('two'), $this->renderer());
        } finally {
            $lock->release();
        }

        $this->assertSame('<p>one</p>', $served->html);
        $this->assertFalse($served->stale);
        $this->assertSame(1, $this->builds);
    }

    public function test_without_a_copy_a_waiter_renders_after_the_lock_wait(): void
    {
        $cache = $this->cache(lockWaitSeconds: 1);
        $lock = $this->cache()->renderLock(self::PAGE);
        $this->assertTrue($lock->get());
        try {
            $started = microtime(true);
            $served = $cache->render(self::PAGE, self::WORKSPACE, $this->build('one'), $this->renderer());
            $waited = microtime(true) - $started;
        } finally {
            $lock->release();
        }

        $this->assertSame('<p>one</p>', $served->html);
        $this->assertGreaterThanOrEqual(0.9, $waited);
        $this->assertLessThan(3, $waited);
    }

    public function test_variants_are_cached_separately(): void
    {
        $cache = $this->cache();
        $cache->render(self::PAGE, self::WORKSPACE, $this->build('html'), $this->renderer());
        $feed = $cache->render(self::PAGE, self::WORKSPACE, $this->build('feed'), $this->renderer(), 'atom');

        $this->assertSame('<p>feed</p>', $feed->html);
        $this->assertSame('<p>html</p>', $cache->render(self::PAGE, self::WORKSPACE, $this->build('x'), $this->renderer())->html);
    }

    private function cache(int $lockWaitSeconds = 3): PageCache
    {
        return new PageCache($this->stamps, new DatabaseOutage, $lockWaitSeconds);
    }

    /**
     * @return \Closure(): PageView
     */
    private function build(string $title, ?CarbonImmutable $expiresAt = null): \Closure
    {
        return function () use ($title, $expiresAt): PageView {
            $this->builds++;

            return new PageView(
                title: $title,
                descriptionHtml: null,
                overall: DisplayStatus::Operational,
                overallLabelKey: 'all_operational',
                sections: [],
                openIncidents: [],
                pastIncidents: [],
                activeMaintenance: [],
                upcomingMaintenance: [],
                expiresAt: $expiresAt,
                generatedAt: CarbonImmutable::now(),
                timezone: 'UTC',
                locale: 'en',
            );
        };
    }

    /**
     * @return \Closure(): PageView
     */
    private function failing(\Throwable $e): \Closure
    {
        return function () use ($e): never {
            throw $e;
        };
    }

    /**
     * @return \Closure(PageView): string
     */
    private function renderer(): \Closure
    {
        return fn (PageView $view): string => '<p>'.$view->title.'</p>';
    }
}
