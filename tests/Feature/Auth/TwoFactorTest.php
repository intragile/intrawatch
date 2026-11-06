<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use LogicException;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $stateful = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/login'];

    private function userWithTwoFactor(): User
    {
        $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-one', 'recovery-two'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }

    private function otpFor(User $user): string
    {
        $encrypted = $user->two_factor_secret;
        if (! is_string($encrypted)) {
            throw new LogicException('The user has no two-factor secret.');
        }

        $secret = decrypt($encrypted);
        if (! is_string($secret)) {
            throw new LogicException('The two-factor secret could not be decrypted.');
        }

        return (new Google2FA)->getCurrentOtp($secret);
    }

    public function test_login_requires_totp_when_enabled(): void
    {
        $user = $this->userWithTwoFactor();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'], $this->stateful)
            ->assertOk()
            ->assertExactJson(['two_factor' => true]);
        $this->getJson('/api/v1/me', $this->stateful)->assertUnauthorized();

        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => '000000'], $this->stateful)->assertStatus(422);
        $this->getJson('/api/v1/me', $this->stateful)->assertUnauthorized();

        $code = $this->otpFor($user);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $code], $this->stateful)->assertSuccessful();
        $this->getJson('/api/v1/me', $this->stateful)
            ->assertOk()
            ->assertJsonPath('data.two_factor_enabled', true);
    }

    public function test_recovery_code_completes_login(): void
    {
        $user = $this->userWithTwoFactor();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'], $this->stateful)
            ->assertExactJson(['two_factor' => true]);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['recovery_code' => 'recovery-one'], $this->stateful)
            ->assertSuccessful();
        $this->getJson('/api/v1/me', $this->stateful)->assertOk();
    }

    public function test_two_factor_management_requires_recent_password_confirmation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->postJson('/api/v1/auth/user/two-factor-authentication', [], $this->stateful)->assertStatus(423);
        $this->getJson('/api/v1/auth/user/two-factor-recovery-codes', $this->stateful)->assertStatus(423);
        $this->assertNull($user->refresh()->two_factor_secret);

        $this->postJson('/api/v1/auth/user/confirm-password', ['password' => 'wrong'], $this->stateful)->assertStatus(422);
        $this->postJson('/api/v1/auth/user/confirm-password', ['password' => 'password'], $this->stateful)->assertCreated();

        $this->postJson('/api/v1/auth/user/two-factor-authentication', [], $this->stateful)->assertSuccessful();
        $this->assertNotNull($user->refresh()->two_factor_secret);
    }

    public function test_user_can_enable_and_confirm_two_factor(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->postJson('/api/v1/auth/user/confirm-password', ['password' => 'password'], $this->stateful)->assertCreated();

        $this->postJson('/api/v1/auth/user/two-factor-authentication', [], $this->stateful)->assertSuccessful();
        $user->refresh();
        $this->assertNull($user->two_factor_confirmed_at);
        $this->getJson('/api/v1/auth/user/two-factor-qr-code', $this->stateful)->assertOk()->assertJsonStructure(['svg', 'url']);
        $this->getJson('/api/v1/auth/user/two-factor-recovery-codes', $this->stateful)->assertOk()->assertJsonCount(8);

        $code = $this->otpFor($user);
        $this->postJson('/api/v1/auth/user/confirmed-two-factor-authentication', ['code' => $code], $this->stateful)
            ->assertSuccessful();

        $this->assertNotNull($user->refresh()->two_factor_confirmed_at);
    }
}
