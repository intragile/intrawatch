<?php

namespace App\Actions;

use App\Exceptions\SetupAlreadyCompleted;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CompleteSetup
{
    public function __construct(private readonly CreateWorkspace $createWorkspace) {}

    /**
     * Create the first user and their workspace. Runs at most once per installation.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws SetupAlreadyCompleted
     */
    public function handle(array $input): User
    {
        $lock = Cache::lock('setup', 10);

        if (! $lock->get()) {
            throw SetupAlreadyCompleted::inProgress();
        }

        try {
            return DB::transaction(function () use ($input): User {
                if (User::query()->exists()) {
                    throw SetupAlreadyCompleted::completed();
                }

                /** @var array{name: string, email: string, password: string, workspace_name: string} $data */
                $data = Validator::make($input, [
                    'name' => ['required', 'string', 'max:120'],
                    'email' => ['required', 'string', 'email', 'max:255'],
                    'password' => ['required', 'string', Password::min(10)],
                    'workspace_name' => ['required', 'string', 'max:120'],
                ])->validate();

                $user = User::create([
                    'name' => $data['name'],
                    'email' => mb_strtolower($data['email']),
                    'password' => $data['password'],
                ]);
                $this->createWorkspace->handle($user, $data['workspace_name']);

                return $user;
            });
        } finally {
            $lock->release();
        }
    }
}
