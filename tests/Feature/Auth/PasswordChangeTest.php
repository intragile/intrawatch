<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT = 'currentsessionidcurrentsessionidcurrentx';

    /** @var array<string, string> */
    private array $stateful = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/admin/account'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database']);
    }

    private function storeSession(string $id, User $owner): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $owner->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'curl/8', 'payload' => base64_encode(serialize([])), 'last_activity' => time(),
        ]);
    }

    /**
     * @param  array<string, string>  $body
     * @return TestResponse<Response>
     */
    private function change(User $user, array $body): TestResponse
    {
        return $this->actingAs($user)
            ->withCredentials()->withCookie(config()->string('session.cookie'), self::CURRENT)
            ->putJson('/api/v1/me/password', $body, $this->stateful);
    }

    public function test_change_keeps_this_session_and_ends_every_other_way_in(): void
    {
        Event::fake([OtherDeviceLogout::class]);
        $user = User::factory()->create();
        $other = User::factory()->create();
        $remember = $user->remember_token;
        $user->createToken('ci', ['workspace:read']);
        $this->storeSession(self::CURRENT, $user);
        $this->storeSession('othersessionidothersessionidothersession1', $user);
        $this->storeSession('strangersessionidstrangersessionidstrang', $other);

        $this->change($user, [
            'current_password' => 'password',
            'password' => 'a-new-long-password',
            'password_confirmation' => 'a-new-long-password',
        ])->assertNoContent();

        $user->refresh();
        $this->assertTrue(Hash::check('a-new-long-password', $user->password));
        $this->assertNotSame($remember, $user->remember_token);
        $this->assertSame([self::CURRENT], DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
        // As with a password reset, API tokens made while the old password was valid do not survive.
        $this->assertSame(0, $user->tokens()->count());
        Event::assertDispatched(OtherDeviceLogout::class);

        $event = DB::table('audit_events')->where('action', 'auth.password_changed')->sole();
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertNull($event->workspace_id);
        $this->assertIsString($event->changes);
        $this->assertSame(['other_sessions' => [1, 0], 'api_keys' => [1, 0]], json_decode($event->changes, true));
        $this->assertStringNotContainsString('a-new-long-password', $event->changes);
    }

    public function test_this_browser_gets_a_fresh_remember_cookie(): void
    {
        $user = User::factory()->create();
        $recaller = $this->app['auth']->guard('web')->getRecallerName();

        $this->withCookie($recaller, $user->id.'|'.$user->remember_token.'|x')
            ->change($user, [
                'current_password' => 'password',
                'password' => 'a-new-long-password',
                'password_confirmation' => 'a-new-long-password',
            ])
            ->assertNoContent()
            ->assertCookie($recaller);
    }

    public function test_wrong_current_password_is_a_422_and_changes_nothing(): void
    {
        $user = User::factory()->create();
        $user->createToken('ci', ['workspace:read']);
        $this->storeSession('othersessionidothersessionidothersession1', $user);

        $this->change($user, [
            'current_password' => 'not-my-password',
            'password' => 'a-new-long-password',
            'password_confirmation' => 'a-new-long-password',
        ])->assertUnprocessable()->assertJsonValidationErrors(['current_password']);

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame(1, DB::table('sessions')->where('id', 'othersessionidothersessionidothersession1')->count());
        $this->assertDatabaseMissing('audit_events', ['action' => 'auth.password_changed']);
    }

    public function test_new_password_follows_the_reset_rules(): void
    {
        $user = User::factory()->create();

        $this->change($user, ['current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->change($user, ['current_password' => 'password', 'password' => 'a-new-long-password', 'password_confirmation' => 'something-else'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->change($user, ['current_password' => 'password', 'password' => 'password', 'password_confirmation' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }

    public function test_api_tokens_cannot_change_the_password(): void
    {
        $user = User::factory()->create();
        $plain = $user->createToken('ci', ['workspace:read'])->plainTextToken;

        $this->withToken($plain)->putJson('/api/v1/me/password', [
            'current_password' => 'password',
            'password' => 'a-new-long-password',
            'password_confirmation' => 'a-new-long-password',
        ])->assertForbidden();

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_password_checks_are_throttled(): void
    {
        $user = User::factory()->create();
        $body = ['current_password' => 'wrong-password', 'password' => 'a-new-long-password', 'password_confirmation' => 'a-new-long-password'];

        for ($i = 0; $i < 5; $i++) {
            $this->change($user, $body)->assertUnprocessable();
        }

        $this->change($user, $body)->assertTooManyRequests();
        // The limit is shared with signing out other sessions, which checks the password as well.
        $this->postJson('/api/v1/me/sessions/sign-out-others', ['password' => 'password'], $this->stateful)->assertTooManyRequests();
    }
}
