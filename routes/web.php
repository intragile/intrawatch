<?php

use App\Http\Controllers\Status\StatusBadgeController;
use App\Http\Controllers\Status\StatusFeedController;
use App\Http\Controllers\Status\StatusPageController;
use App\Http\Controllers\Status\StatusSummaryController;
use App\Http\Middleware\ThrottleStatusRequests;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Public status pages: no session, no cookies, no CSRF, so they stay cacheable and work without the database.
Route::withoutMiddleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    PreventRequestForgery::class,
])->middleware(ThrottleStatusRequests::class.':'.ThrottleStatusRequests::LIMITER)->group(function (): void {
    $slug = '[a-z0-9][a-z0-9-]{0,62}[a-z0-9]';

    Route::get('/status/{slug}', [StatusPageController::class, 'show'])
        ->where('slug', $slug);

    Route::get('/status/{slug}/feed.atom', [StatusFeedController::class, 'atom'])->where('slug', $slug);
    Route::get('/status/{slug}/feed.rss', [StatusFeedController::class, 'rss'])->where('slug', $slug);
    Route::get('/status/{slug}/summary.json', [StatusSummaryController::class, 'show'])->where('slug', $slug);
    Route::get('/status/{slug}/badge.svg', [StatusBadgeController::class, 'overall'])->where('slug', $slug);
    Route::get('/status/{slug}/badge/{monitor}/{kind}.svg', [StatusBadgeController::class, 'monitor'])
        ->where(['slug' => $slug, 'monitor' => '[0-9]+', 'kind' => 'status|uptime|latency']);

    // The same endpoints on a custom domain (ResolveStatusHost); a 404 on every other host.
    Route::get('/feed.atom', [StatusFeedController::class, 'atom']);
    Route::get('/feed.rss', [StatusFeedController::class, 'rss']);
    Route::get('/summary.json', [StatusSummaryController::class, 'show']);
    Route::get('/badge.svg', [StatusBadgeController::class, 'overall']);
    Route::get('/badge/{monitor}/{kind}.svg', [StatusBadgeController::class, 'monitor'])
        ->where(['monitor' => '[0-9]+', 'kind' => 'status|uptime|latency']);

    // The page of a custom domain (ResolveStatusHost); a 404 on every other host.
    Route::get('/', [StatusPageController::class, 'home']);
});
