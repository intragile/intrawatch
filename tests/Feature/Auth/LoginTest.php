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
        // An unknown address is rejected by the broker without its own cooldown, so only the throttle can answer 429.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => $i % 2 ? 'Nobody@Example.com' : 'nobody@example.com'], $this->stateful)
                ->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'], $this->stateful)->assertStatus(429);
    }
}
