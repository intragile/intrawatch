<?php

use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MonitorActionController;
use App\Http\Controllers\Api\MonitorBulkController;
use App\Http\Controllers\Api\MonitorController;
use App\Http\Controllers\Api\MonitorHistoryController;
use App\Http\Controllers\Api\MonitorTypeController;
use App\Http\Controllers\Api\OpenApiController;
use App\Http\Controllers\Api\PushController;
use App\Http\Controllers\Api\SetupController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Controllers\PingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/ping', PingController::class);
    Route::get('/openapi.json', OpenApiController::class)->middleware('throttle:openapi');
    Route::get('/setup', [SetupController::class, 'show']);
    Route::post('/setup', [SetupController::class, 'store'])->middleware(['throttle:setup', 'session.required']);

    // Push and cron monitors report here; the token in the path is the only credential.
    Route::prefix('/push/{token}')->where(['token' => '[A-Za-z0-9]+'])->middleware('throttle:push')->group(function (): void {
        $methods = ['GET', 'POST', 'HEAD'];
        Route::match($methods, '/', [PushController::class, 'ping']);
        Route::match($methods, '/start', [PushController::class, 'start']);
        Route::match($methods, '/fail', [PushController::class, 'fail']);
        Route::match($methods, '/{exitCode}', [PushController::class, 'exit'])->whereNumber('exitCode');
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', MeController::class);

        Route::get('/monitor-types', MonitorTypeController::class);

        Route::get('/workspaces', [WorkspaceController::class, 'index']);
        Route::post('/workspaces', [WorkspaceController::class, 'store']);

        // Child models are resolved through the workspace (scoped bindings), so an id that belongs to
        // another workspace is a 404. Every other route parameter is a numeric id.
        Route::prefix('/workspaces/{workspace}')->middleware('workspace')->scopeBindings()->group(function (): void {
            Route::patch('/', [WorkspaceController::class, 'update'])->middleware('ability:workspace:manage');
            Route::delete('/', [WorkspaceController::class, 'destroy'])->middleware('ability:workspace:manage');

            Route::get('/members', [MemberController::class, 'index'])->middleware('ability:workspace:read');
            Route::middleware('ability:members:manage')->group(function (): void {
                Route::post('/members', [MemberController::class, 'store']);
                Route::patch('/members/{user}', [MemberController::class, 'update'])->whereNumber('user');
                Route::delete('/members/{user}', [MemberController::class, 'destroy'])->whereNumber('user');
            });

            Route::get('/audit', AuditController::class)->middleware('ability:audit:read');

            Route::middleware(['ability:monitors:read', 'can:viewMonitors,workspace'])->group(function (): void {
                Route::get('/monitors', [MonitorController::class, 'index']);
                Route::get('/monitors/{monitor}', [MonitorController::class, 'show'])->whereNumber('monitor');
                Route::get('/monitors/{monitor}/results', [MonitorHistoryController::class, 'results'])->whereNumber('monitor');
                Route::get('/monitors/{monitor}/rollups', [MonitorHistoryController::class, 'rollups'])->whereNumber('monitor');
                Route::get('/monitors/{monitor}/events', [MonitorHistoryController::class, 'events'])->whereNumber('monitor');
                Route::get('/tags', [TagController::class, 'index']);
            });

            Route::middleware(['ability:monitors:write', 'can:manageMonitors,workspace'])->group(function (): void {
                Route::post('/monitors', [MonitorController::class, 'store']);
                Route::post('/monitors/bulk', MonitorBulkController::class);
                Route::patch('/monitors/{monitor}', [MonitorController::class, 'update'])->whereNumber('monitor');
                Route::delete('/monitors/{monitor}', [MonitorController::class, 'destroy'])->whereNumber('monitor');
                Route::post('/monitors/{monitor}/pause', [MonitorActionController::class, 'pause'])->whereNumber('monitor');
                Route::post('/monitors/{monitor}/resume', [MonitorActionController::class, 'resume'])->whereNumber('monitor');
                Route::post('/monitors/{monitor}/check', [MonitorActionController::class, 'check'])->whereNumber('monitor');
                Route::post('/monitors/{monitor}/push-token', [MonitorActionController::class, 'rotatePushToken'])->whereNumber('monitor');

                Route::post('/tags', [TagController::class, 'store']);
                Route::patch('/tags/{tag}', [TagController::class, 'update'])->whereNumber('tag');
                Route::delete('/tags/{tag}', [TagController::class, 'destroy'])->whereNumber('tag');
            });

            Route::get('/tokens', [TokenController::class, 'index']);
            Route::post('/tokens', [TokenController::class, 'store']);
            Route::delete('/tokens/{id}', [TokenController::class, 'destroy'])->whereNumber('id');
        });
    });
});
