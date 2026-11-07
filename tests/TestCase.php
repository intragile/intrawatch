<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The status page cache is file based; tests use a directory of their own per process so they never
        // touch a checkout's rendered pages and parallel runs do not share stamps.
        $path = sys_get_temp_dir().'/intrawatch-status-pages-'.getmypid();
        config([
            'cache.stores.status_pages.path' => $path,
            'cache.stores.status_pages.lock_path' => $path.'/locks',
        ]);
        Cache::forgetDriver('status_pages');
    }
}
