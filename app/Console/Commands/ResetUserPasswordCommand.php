<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use App\Support\SignOutEverywhere;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

#[Signature('intrawatch:user:reset-password {email} {--password=}')]
#[Description('Set a new password for a user, sign out their existing sessions and revoke their API tokens')]
class ResetUserPasswordCommand extends Command
{
    public function handle(SignOutEverywhere $signOutEverywhere): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No user with the e-mail address {$email}.");

            return self::FAILURE;
        }

        $plain = $this->option('password');
        if (! is_string($plain) || $plain === '') {
            $plain = password('New password');
        }

        $validator = Validator::make(['password' => $plain], ['password' => ['required', Password::min(10)]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $plain, $signOutEverywhere): void {
            $user->forceFill(['password' => $plain])->save();
            // Sessions, the remember cookie and tokens obtained with the old password end with it, as with the e-mail reset.
            $signOutEverywhere($user);

            // The password itself is never part of the trail.
            Audit::record('auth.password_reset', $user);
        });

        $this->info("Password reset for {$email}.");

        return self::SUCCESS;
    }
}
