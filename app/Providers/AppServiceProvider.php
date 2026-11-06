<?php

namespace App\Providers;

use App\Models\Workspace;
use App\Monitoring\Engine\GroupEvaluator;
use App\Monitoring\Engine\MaintenanceOracle;
use App\Monitoring\Engine\NoMaintenance;
use App\Monitoring\Engine\NullGroupEvaluator;
use App\Policies\WorkspacePolicy;
use App\Support\CurrentWorkspace;
use App\Support\RecordAuthEvent;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Server;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentWorkspace::class);
        $this->app->bind(MaintenanceOracle::class, NoMaintenance::class);
        $this->app->bind(GroupEvaluator::class, NullGroupEvaluator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn (): Password => Password::min(10));

        Gate::policy(Workspace::class, WorkspacePolicy::class);

        Event::listen(Login::class, [RecordAuthEvent::class, 'handleLogin']);
        Event::listen(Failed::class, [RecordAuthEvent::class, 'handleFailed']);
        Event::listen(TwoFactorAuthenticationEnabled::class, [RecordAuthEvent::class, 'handleTwoFactorEnabled']);
        Event::listen(TwoFactorAuthenticationDisabled::class, [RecordAuthEvent::class, 'handleTwoFactorDisabled']);

        // The spec is served from the API itself; Scramble's own UI and JSON routes stay off.
        Scramble::configure()
            ->expose(false)
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->info->title = config()->string('app.name');
                $openApi->info->version = config()->string('app.version');
                // A relative server keeps the exported document independent of APP_URL.
                $openApi->servers = [new Server('/api/v1')];
                // Stable ordering keeps the exported document diffable.
                usort($openApi->paths, fn ($a, $b): int => strcmp($a->path, $b->path));
            });
    }
}
