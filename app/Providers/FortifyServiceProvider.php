<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\GenericPasswordResetLinkResponse;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, GenericPasswordResetLinkResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, GenericPasswordResetLinkResponse::class);
    }

    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // The reset form is served by the SPA, so the mail links to its route.
        ResetPassword::createUrlUsing(function (mixed $notifiable, string $token): string {
            $email = $notifiable instanceof CanResetPasswordContract ? $notifiable->getEmailForPasswordReset() : '';

            // Built from the configured URL, never from the request Host header.
            return rtrim(config()->string('app.url'), '/').'/reset-password/'.$token.'?email='.urlencode($email);
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->string(Fortify::username())->toString()).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            $loginId = $request->hasSession() ? $request->session()->get('login.id') : null;

            return Limit::perMinute(5)->by(is_string($loginId) || is_int($loginId) ? (string) $loginId : $request->ip() ?? 'unknown');
        });

        RateLimiter::for('setup', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));

        RateLimiter::for('password-email', function (Request $request) {
            return Limit::perMinute(5)->by(Str::lower($request->string('email')->toString()).'|'.$request->ip());
        });

        // Fortify has no limiter option for the forgot-password route, and no hook for the session
        // requirement of the login routes, so both are attached here.
        $this->app->booted(function (): void {
            $routes = Route::getRoutes();
            $routes->refreshNameLookups();
            $routes->getByName('password.email')?->middleware('throttle:password-email');
            $routes->getByName('login.store')?->middleware('session.required');
            $routes->getByName('two-factor.login.store')?->middleware('session.required');
        });
    }
}
