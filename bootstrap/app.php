<?php

use App\Http\Middleware\EnsureTokenAbility;
use App\Http\Middleware\RequireSession;
use App\Http\Middleware\ResolveWorkspace;
use App\Http\Problem\ProblemRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Env;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        $trusted = Env::get('TRUSTED_PROXIES');
        $trusted = is_string($trusted) ? trim($trusted) : '';
        if ($trusted !== '') {
            $middleware->trustProxies(at: $trusted === '*'
                ? '*'
                : array_values(array_filter(array_map('trim', explode(',', $trusted)), fn (string $p) => $p !== '')));
        }

        $middleware->alias([
            'workspace' => ResolveWorkspace::class,
            'ability' => EnsureTokenAbility::class,
            'session.required' => RequireSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (Throwable $e, Request $request) => $request->is('api/*') ? ProblemRenderer::render($e) : null);
    })->create();
