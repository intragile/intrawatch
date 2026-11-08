<?php

namespace Tests\Feature\Auth;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_reset_ends_every_database_session_of_the_user(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $other = User::factory()->create();
        $remember = $user->remember_token;
        foreach ([['a', $user], ['b', $user], ['c', $other]] as [$id, $owner]) {
            DB::table('sessions')->insert([
                'id' => $id, 'user_id' => $owner->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => time(),
            ]);
        }

        $reset = Password::broker()->createToken($user);
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $reset,
            'email' => 'ada@example.com',
            'password' => 'a-new-long-password',
            'password_confirmation' => 'a-new-long-password',
        ])->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
        $this->assertNotSame($remember, $user->refresh()->remember_token);
    }
}
