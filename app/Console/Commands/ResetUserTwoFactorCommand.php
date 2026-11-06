<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('intrawatch:user:reset-2fa {email}')]
#[Description('Disable two-factor authentication for a user who lost their device and recovery codes')]
class ResetUserTwoFactorCommand extends Command
{
    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No user with the e-mail address {$email}.");

            return self::FAILURE;
        }

        DB::transaction(function () use ($user): void {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            Audit::record('auth.two_factor_reset', $user);
        });

        $this->info("Two-factor authentication disabled for {$email}.");

        return self::SUCCESS;
    }
}
