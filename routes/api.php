<?php

use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\IncidentController;
use App\Http\Controllers\Api\IncidentUpdateController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\MaintenanceController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MonitorActionController;
use App\Http\Controllers\Api\MonitorBulkController;
use App\Http\Controllers\Api\MonitorController;
use App\Http\Controllers\Api\MonitorHistoryController;
use App\Http\Controllers\Api\MonitorTypeController;
use App\Http\Controllers\Api\NotificationChannelController;
use App\Http\Controllers\Api\NotificationChannelTestController;
use App\Http\Controllers\Api\NotificationChannelTypeController;
use App\Http\Controllers\Api\OpenApiController;
use App\Http\Controllers\Api\PushController;
use App\Http\Controllers\Api\SetupController;
use App\Http\Controllers\Api\StatusPageController;
use App\Http\Controllers\Api\StatusPageLayoutController;
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
        Route::get('/notification-channel-types', NotificationChannelTypeController::class);

        Route::get('/workspaces', [WorkspaceController::class, 'index']);
        Route::post('/workspaces', [WorkspaceController::class, 'store']);

        // The e-mailed link carries the token in the URL fragment, which the browser never sends; the SPA
        // posts it in the body, and the signed-in user's address must match the invitation.
        Route::post('/invitations/accept', [InvitationController::class, 'accept'])->middleware('throttle:invitation-accept');

        // Child models are resolved through the workspace (scoped bindings), so an id that belongs to
        // another workspace is a 404. Every other route parameter is a numeric id, except import ULIDs.
        Route::prefix('/workspaces/{workspace}')->middleware('workspace')->scopeBindings()->group(function (): void {
            Route::patch('/', [WorkspaceController::class, 'update'])->middleware('ability:workspace:manage');
            Route::delete('/', [WorkspaceController::class, 'destroy'])->middleware('ability:workspace:manage');

            Route::get('/members', [MemberController::class, 'index'])->middleware('ability:workspace:read');
            Route::middleware('ability:members:manage')->group(function (): void {
                Route::post('/members', [MemberController::class, 'store'])->middleware('throttle:member-invites');
                Route::get('/invitations', [InvitationController::class, 'index']);
                Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy'])->whereNumber('invitation');
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

            Route::middleware(['ability:notifications:read', 'can:viewNotifications,workspace'])->group(function (): void {
                Route::get('/notification-channels', [NotificationChannelController::class, 'index']);
                Route::get('/notification-channels/{channel}', [NotificationChannelController::class, 'show'])->whereNumber('channel');
                Route::get('/alerts', [AlertController::class, 'index']);
                Route::get('/monitors/{monitor}/alerts', [AlertController::class, 'monitor'])->whereNumber('monitor')
                    ->middleware(['ability:monitors:read', 'can:viewMonitors,workspace']);
            });

            Route::middleware(['ability:notifications:write', 'can:manageNotifications,workspace'])->group(function (): void {
                Route::post('/notification-channels', [NotificationChannelController::class, 'store']);
                Route::patch('/notification-channels/{channel}', [NotificationChannelController::class, 'update'])->whereNumber('channel');
                Route::delete('/notification-channels/{channel}', [NotificationChannelController::class, 'destroy'])->whereNumber('channel');

                Route::middleware('throttle:notification-test')->group(function (): void {
                    Route::post('/notification-channels/test', [NotificationChannelTestController::class, 'draft']);
                    Route::post('/notification-channels/{channel}/test', [NotificationChannelTestController::class, 'saved'])->whereNumber('channel');
                });
            });

            Route::middleware(['ability:incidents:read', 'can:viewIncidents,workspace'])->group(function (): void {
                Route::get('/incidents', [IncidentController::class, 'index']);
                Route::get('/incidents/{incident}', [IncidentController::class, 'show'])->whereNumber('incident');
                Route::get('/maintenances', [MaintenanceController::class, 'index']);
                Route::get('/maintenances/{maintenance}', [MaintenanceController::class, 'show'])->whereNumber('maintenance');
            });

            Route::middleware(['ability:incidents:write', 'can:manageIncidents,workspace'])->group(function (): void {
                Route::post('/incidents', [IncidentController::class, 'store']);
                Route::patch('/incidents/{incident}', [IncidentController::class, 'update'])->whereNumber('incident');
                Route::delete('/incidents/{incident}', [IncidentController::class, 'destroy'])->whereNumber('incident');
                Route::post('/incidents/{incident}/updates', [IncidentUpdateController::class, 'store'])->whereNumber('incident');
                Route::patch('/incidents/{incident}/updates/{update}', [IncidentUpdateController::class, 'update'])->whereNumber(['incident', 'update']);
                Route::delete('/incidents/{incident}/updates/{update}', [IncidentUpdateController::class, 'destroy'])->whereNumber(['incident', 'update']);
                Route::post('/maintenances', [MaintenanceController::class, 'store']);
                Route::patch('/maintenances/{maintenance}', [MaintenanceController::class, 'update'])->whereNumber('maintenance');
                Route::delete('/maintenances/{maintenance}', [MaintenanceController::class, 'destroy'])->whereNumber('maintenance');
                Route::post('/maintenances/{maintenance}/end', [MaintenanceController::class, 'end'])->whereNumber('maintenance');
            });

            Route::middleware(['ability:status-pages:read', 'can:viewStatusPages,workspace'])->group(function (): void {
                Route::get('/status-pages', [StatusPageController::class, 'index']);
                Route::get('/status-pages/{statusPage}', [StatusPageController::class, 'show'])->whereNumber('statusPage');
            });

            Route::middleware(['ability:status-pages:write', 'can:manageStatusPages,workspace'])->group(function (): void {
                Route::post('/status-pages', [StatusPageController::class, 'store']);
                Route::patch('/status-pages/{statusPage}', [StatusPageController::class, 'update'])->whereNumber('statusPage');
                Route::delete('/status-pages/{statusPage}', [StatusPageController::class, 'destroy'])->whereNumber('statusPage');
                Route::put('/status-pages/{statusPage}/layout', [StatusPageLayoutController::class, 'update'])->whereNumber('statusPage');
            });

            // Imports create entities of every kind, so they need the workspace admin role and token ability.
            Route::middleware(['ability:workspace:manage', 'can:update,workspace'])->group(function (): void {
                Route::get('/imports', [ImportController::class, 'index']);
                Route::post('/imports', [ImportController::class, 'store'])->middleware('throttle:import-upload');
                Route::get('/imports/{import}', [ImportController::class, 'show'])->whereUlid('import');
                Route::delete('/imports/{import}', [ImportController::class, 'destroy'])->whereUlid('import');
                Route::post('/imports/{import}/run', [ImportController::class, 'run'])->whereUlid('import');
            });

            Route::get('/tokens', [TokenController::class, 'index']);
            Route::post('/tokens', [TokenController::class, 'store']);
            Route::delete('/tokens/{id}', [TokenController::class, 'destroy'])->whereNumber('id');
        });
    });
});

// Uptime Kuma's push URL shape, so push clients moved from Kuma only change the host. Same handler, token
// rule and limit as the versioned route above.
Route::match(['GET', 'POST', 'HEAD'], '/push/{token}', [PushController::class, 'ping'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:push');
