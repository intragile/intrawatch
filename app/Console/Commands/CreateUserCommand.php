<?php

namespace App\Console\Commands;

use App\Actions\CreateWorkspace;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

#[Signature('intrawatch:user:create {email} {--name=} {--password=} {--owner-of=}')]
#[Description('Create a user, optionally as the owner of a workspace (created when missing)')]
class CreateUserCommand extends Command
{
    public function handle(CreateWorkspace $createWorkspace): int
    {
        $email = mb_strtolower((string) $this->argument('email'));
        $plain = $this->option('password');
        if (! is_string($plain) || $plain === '') {
            $plain = password('Password');
        }
        $name = $this->option('name');
        $ownerOf = $this->option('owner-of');

        $validator = Validator::make(
            ['email' => $email, 'password' => $plain],
            [
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', Password::min(10)],
            ],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => is_string($name) && $name !== '' ? $name : $email,
            'email' => $email,
            'password' => $plain,
        ]);

        if (is_string($ownerOf) && $ownerOf !== '') {
            $workspace = Workspace::where('slug', $ownerOf)->first();
            if ($workspace === null) {
                $createWorkspace->handle($user, $ownerOf);
            } else {
                $user->workspaces()->attach($workspace, ['role' => WorkspaceRole::Owner->value]);
            }
        }

        $this->info("User {$email} created.");

        return self::SUCCESS;
    }
}
