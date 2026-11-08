<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\Audit;
use App\Support\SignOutEverywhere;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(private readonly SignOutEverywhere $signOutEverywhere) {}

    /**
     * Validate and reset the user's forgotten password. Whoever knew the old password may hold a session,
     * a remember cookie or an API token obtained with it, so every one of them ends together with the change.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        DB::transaction(function () use ($user, $input): void {
            $user->forceFill(['password' => Hash::make($input['password'])])->save();
            ($this->signOutEverywhere)($user);

            // The password itself is never part of the trail.
            Audit::recordGlobal('auth.password_reset', $user);
        });
    }
}
