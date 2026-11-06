<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;

/**
 * Turns authentication events into audit entries. None of them belongs to a workspace.
 * Registered explicitly in the service provider; keep it outside app/Listeners so that event
 * auto-discovery does not register it a second time.
 */
class RecordAuthEvent
{
    public function handleLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            Audit::recordGlobal('auth.login', $event->user);
        }
    }

    public function handleFailed(Failed $event): void
    {
        // Only the attempted address is kept; the password never leaves the guard.
        $email = $event->credentials['email'] ?? null;
        $changes = is_string($email) && $email !== '' ? ['email' => [null, mb_strtolower($email)]] : [];

        Audit::recordGlobal('auth.login_failed', null, $changes);
    }

    public function handleTwoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        Audit::recordGlobal('auth.two_factor_enabled', $event->user);
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        Audit::recordGlobal('auth.two_factor_disabled', $event->user);
    }
}
