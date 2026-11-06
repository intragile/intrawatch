<?php

use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\SetupController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Controllers\PingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/ping', PingController::class);
    Route::get('/setup', [SetupController::class, 'show']);
    Route::post('/setup', [SetupController::class, 'store'])->middleware('throttle:setup');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', MeController::class);

        Route::get('/workspaces', [WorkspaceController::class, 'index']);
        Route::post('/workspaces', [WorkspaceController::class, 'store']);

        Route::prefix('/workspaces/{workspace}')->middleware('workspace')->group(function (): void {
            Route::patch('/', [WorkspaceController::class, 'update']);
            Route::delete('/', [WorkspaceController::class, 'destroy']);

            Route::get('/members', [MemberController::class, 'index']);
            Route::post('/members', [MemberController::class, 'store']);
            Route::patch('/members/{user}', [MemberController::class, 'update']);
            Route::delete('/members/{user}', [MemberController::class, 'destroy']);
        });
    });
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
