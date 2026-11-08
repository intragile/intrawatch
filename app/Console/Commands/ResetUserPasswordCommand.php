<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

#[Signature('intrawatch:user:reset-password {email} {--password=}')]
#[Description('Set a new password for a user, sign out their existing sessions and revoke their API tokens')]
class ResetUserPasswordCommand extends Command
{
    public function handle(): int
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

        DB::transaction(function () use ($user, $plain): void {
            $user->forceFill(['password' => $plain, 'remember_token' => Str::random(60)])->save();

            if (config('session.driver') === 'database') {
                $connection = config('session.connection');
                DB::connection(is_string($connection) && $connection !== '' ? $connection : null)
                    ->table(config()->string('session.table'))
                    ->where('user_id', $user->id)
                    ->delete();
            }

            // Tokens minted with the old password must not outlive it, as with the e-mail reset.
            $user->tokens()->delete();

            // The password itself is never part of the trail.
            Audit::record('auth.password_reset', $user);
        });

        $this->info("Password reset for {$email}.");

        return self::SUCCESS;
    }
}
