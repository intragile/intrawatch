<?php

namespace Tests\Feature\Auth;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_user_command_makes_owner(): void
    {
        $this->assertSame(0, Artisan::call('intrawatch:user:create', [
            'email' => 'a@b.co',
            '--password' => 'secret12345',
            '--owner-of' => 'acme',
        ]));

        $user = User::where('email', 'a@b.co')->firstOrFail();
        $workspace = Workspace::where('slug', 'acme')->firstOrFail();
        $this->assertSame(WorkspaceRole::Owner, $user->roleIn($workspace));
    }

    public function test_command_joins_existing_workspace_as_owner(): void
    {
        $workspace = Workspace::factory()->create(['slug' => 'acme']);

        $this->assertSame(0, Artisan::call('intrawatch:user:create', ['email' => 'a@b.co', '--password' => 'secret12345', '--owner-of' => 'acme']));

        $this->assertSame(1, Workspace::count());
        $this->assertSame(WorkspaceRole::Owner, User::firstOrFail()->roleIn($workspace));
    }

    public function test_command_rejects_weak_password_and_duplicates(): void
    {
        $this->assertSame(1, Artisan::call('intrawatch:user:create', ['email' => 'a@b.co', '--password' => 'short']));
        $this->assertSame(0, User::count());

        User::factory()->create(['email' => 'a@b.co']);
        $this->assertSame(1, Artisan::call('intrawatch:user:create', ['email' => 'A@b.co', '--password' => 'secret12345']));
        $this->assertSame(1, User::count());
    }
}
