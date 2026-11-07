<?php

use App\Http\Controllers\Status\StatusPageController;
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
    Route::get('/status/{slug}', [StatusPageController::class, 'show'])
        ->where('slug', '[a-z0-9][a-z0-9-]{0,62}[a-z0-9]');

    // The page of a custom domain (ResolveStatusHost); a 404 on every other host.
    Route::get('/', [StatusPageController::class, 'home']);
});
