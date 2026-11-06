<?php

use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\OpenApiController;
use App\Http\Controllers\Api\SetupController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Controllers\PingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/ping', PingController::class);
    Route::get('/openapi.json', OpenApiController::class);
    Route::get('/setup', [SetupController::class, 'show']);
    Route::post('/setup', [SetupController::class, 'store'])->middleware(['throttle:setup', 'session.required']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', MeController::class);

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

            Route::get('/tokens', [TokenController::class, 'index']);
            Route::post('/tokens', [TokenController::class, 'store']);
            Route::delete('/tokens/{id}', [TokenController::class, 'destroy'])->whereNumber('id');
        });
    });
});
