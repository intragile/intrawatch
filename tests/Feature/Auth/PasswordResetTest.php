<?php

namespace Tests\Feature\Auth;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_revokes_every_api_token_and_is_audited(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->memberOf($workspace, WorkspaceRole::Owner)->create(['email' => 'ada@example.com']);
        $plain = $user->createToken('ci', ['workspace:read'])->plainTextToken;

        $this->withToken($plain)->getJson('/api/v1/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => '']);

        $reset = Password::broker()->createToken($user);
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $reset,
            'email' => 'ada@example.com',
            'password' => 'a-new-long-password',
            'password_confirmation' => 'a-new-long-password',
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.password_reset', 'actor_user_id' => $user->id, 'workspace_id' => null]);

        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();
    }
}
