<?php

namespace Tests;

use App\Listeners\StampStatusPages;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The status page cache is file based; tests use a directory of their own per process so they never
        // touch a checkout's rendered pages and parallel runs do not share stamps. It starts empty for every
        // test, together with the listener's per-process memo, so tests do not depend on their order.
        $path = sys_get_temp_dir().'/intrawatch-status-pages-'.getmypid();
        File::deleteDirectory($path);
        config([
            'cache.stores.status_pages.path' => $path,
            'cache.stores.status_pages.lock_path' => $path.'/locks',
        ]);
        Cache::forgetDriver('status_pages');
        StampStatusPages::reset();
    }

    /**
     * Runs the queue worker until the queue is empty and returns its exit code. The worker stops with exit
     * code 12 once the process uses more than its --memory limit (128 MB by default), and late in a full test
     * run this process is far past that, so the limit is lifted here; phpunit.xml's memory_limit still applies.
     */
    protected function workQueue(string $queue): int
    {
        return Artisan::call('queue:work', ['--stop-when-empty' => true, '--queue' => $queue, '--memory' => 1_000_000]);
    }
}
