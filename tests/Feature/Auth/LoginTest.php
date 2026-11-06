<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $stateful = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/login'];

    public function test_user_can_log_in_and_out(): void
    {
        $user = User::factory()->create();

        $this->getJson('/api/v1/me', $this->stateful)->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'], $this->stateful)
            ->assertSuccessful();
        $this->getJson('/api/v1/me', $this->stateful)
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.two_factor_enabled', false);

        $this->postJson('/api/v1/auth/logout', [], $this->stateful)->assertSuccessful();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', $this->stateful)->assertUnauthorized();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'nope'], $this->stateful)
            ->assertStatus(422);
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'], $this->stateful)
                ->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'], $this->stateful)
            ->assertStatus(429);
    }

    public function test_password_can_be_reset(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email], $this->stateful)->assertSuccessful();
        Notification::assertSentTo($user, ResetPassword::class);

        $token = Password::createToken($user);
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-much-longer-password',
            'password_confirmation' => 'a-much-longer-password',
        ], $this->stateful)->assertSuccessful();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'a-much-longer-password'], $this->stateful)
            ->assertSuccessful();
    }

    public function test_reset_rejects_short_password(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ], $this->stateful)->assertStatus(422);
    }

    public function test_reset_link_ignores_forged_host_header(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->withServerVariables(['HTTP_HOST' => 'evil.test'])
            ->postJson('/api/v1/auth/forgot-password', ['email' => $user->email], $this->stateful)
            ->assertSuccessful();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $mail = $notification->toMail($user);
            $url = $mail instanceof MailMessage ? (string) $mail->actionUrl : '';

            return str_starts_with($url, rtrim(config()->string('app.url'), '/').'/reset-password/')
                && ! str_contains($url, 'evil.test');
        });
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        // An unknown address has no broker cooldown, so only the throttle can answer 429.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => $i % 2 ? 'Nobody@Example.com' : 'nobody@example.com'], $this->stateful)
                ->assertOk();
        }

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'], $this->stateful)->assertStatus(429);
    }

    public function test_forgot_password_does_not_reveal_whether_an_account_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email], $this->stateful);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com'], $this->stateful);
        // A second request for the known address hits the broker's cooldown, which must look the same too.
        $throttled = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email], $this->stateful);

        foreach ([$known, $unknown, $throttled] as $response) {
            $response->assertOk();
            $this->assertSame($known->getContent(), $response->getContent());
        }
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_login_without_a_session_is_a_problem_not_a_silent_success(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'urn:problem:session-unavailable');

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'], [
            'Origin' => 'http://evil.test', 'Referer' => 'http://evil.test/',
        ])->assertStatus(400);
    }

    public function test_app_url_host_is_a_stateful_domain_by_default(): void
    {
        $this->assertFalse(getenv('SANCTUM_STATEFUL_DOMAINS'), 'The default list is under test.');
        config(['app.url' => 'http://intrawatch.test:3000']);
        // Re-evaluate the shipped config file against the new APP_URL.
        $sanctum = require config_path('sanctum.php');
        $this->assertIsArray($sanctum);
        config(['sanctum.stateful' => $sanctum['stateful']]);

        $this->assertSame(['localhost:3000', '127.0.0.1:3000', 'intrawatch.test:3000'], config('sanctum.stateful'));

        $user = User::factory()->create();
        $origin = ['Origin' => 'http://intrawatch.test:3000', 'Referer' => 'http://intrawatch.test:3000/login'];

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'], $origin)
            ->assertSuccessful();
        $this->getJson('/api/v1/me', $origin)->assertOk()->assertJsonPath('data.email', $user->email);
    }
}
