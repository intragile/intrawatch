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
    /**
     * The longest address SMTP can deliver to (RFC 5321 path limit minus the angle brackets).
     */
    private const MAX_EMAIL_LENGTH = 254;

    public function handleLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            Audit::recordGlobal('auth.login', $event->user);
        }
    }

    public function handleFailed(Failed $event): void
    {
        // Only the attempted address is kept; the password never leaves the guard. Anything that is not a
        // plausible address (arbitrary or oversized input) is stored as null.
        $email = $event->credentials['email'] ?? null;
        $changes = is_string($email) && $email !== '' ? ['email' => [null, self::plausibleEmail($email)]] : [];

        Audit::recordGlobal('auth.login_failed', null, $changes);
    }

    private static function plausibleEmail(string $email): ?string
    {
        $email = mb_strtolower(trim($email));

        return mb_strlen($email) <= self::MAX_EMAIL_LENGTH && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            ? $email
            : null;
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
