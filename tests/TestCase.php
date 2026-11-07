<?php

namespace Tests;

use App\Listeners\StampStatusPages;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
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
}
