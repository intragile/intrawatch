<?php

namespace App\Providers;

use App\Models\Workspace;
use App\Policies\WorkspacePolicy;
use App\Support\CurrentWorkspace;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Server;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentWorkspace::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn (): Password => Password::min(10));

        Gate::policy(Workspace::class, WorkspacePolicy::class);

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
