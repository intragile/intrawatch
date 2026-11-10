<?php

use App\Http\Controllers\SpaAssetController;
use App\Http\Controllers\SpaController;
use App\Http\Controllers\SpaManualImageController;
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

$sessionless = [
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    PreventRequestForgery::class,
];

// Public status pages: no session, no cookies, no CSRF, so they stay cacheable and work without the database.
Route::withoutMiddleware($sessionless)->middleware(ThrottleStatusRequests::class.':'.ThrottleStatusRequests::LIMITER)->group(function (): void {
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

    // The page of a custom domain (ResolveStatusHost); a redirect to the admin on every other host.
    Route::get('/', [StatusPageController::class, 'home']);
});

// The admin single-page application: a static shell and its assets, without session or cookies. Custom status
// domains never reach these routes (ResolveStatusHost).
Route::withoutMiddleware($sessionless)->group(function (): void {
    Route::get('/admin/assets/{file}', SpaAssetController::class)->where('file', '[A-Za-z0-9._-]+');
    Route::get('/admin/manual/{locale}/{file}', SpaManualImageController::class)
        ->where(['locale' => '[a-z]{2}', 'file' => '[A-Za-z0-9._-]+']);
    Route::get('/admin/{path?}', SpaController::class)->where('path', '.*');
});
