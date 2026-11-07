<?php

namespace App\Http\Middleware;

use Illuminate\Cache\RateLimiter;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;

/**
 * The `status` rate limiter counted in the status page file cache instead of the default cache store, so
 * public pages keep their limit without a database query per request and still answer while the database
 * (the default store in the image) is down.
 */
final class ThrottleStatusRequests extends ThrottleRequests
{
    public const LIMITER = 'status';

    public function __construct(RateLimiter $limiter)
    {
        $local = new RateLimiter(Cache::store('status_pages'));
        $named = $limiter->limiter(self::LIMITER);
        if ($named !== null) {
            $local->for(self::LIMITER, $named);
        }

        parent::__construct($local);
    }
}
