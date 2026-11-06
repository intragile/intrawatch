<?php

namespace Tests\Feature\Auth;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RecoveryCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_2fa_disables_two_factor_and_is_audited_as_system(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $user->forceFill([
            'two_factor_secret' => encrypt('SECRET'),
            'two_factor_recovery_codes' => encrypt('[]'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->assertSame(0, Artisan::call('intrawatch:user:reset-2fa', ['email' => 'Ada@Example.com']));

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);

        $event = AuditEvent::where('action', 'auth.two_factor_reset')->sole();
        $this->assertSame('system', $event->actor_type);
        $this->assertNull($event->actor_id);
        $this->assertSame('user', $event->subject_type);
        $this->assertSame((string) $user->id, $event->subject_id);
        $this->assertSame(0, AuditEvent::where('action', 'auth.two_factor_disabled')->count());
    }

    public function test_reset_password_sets_new_password_ends_sessions_and_is_audited(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $remember = $user->remember_token;
        DB::table('sessions')->insert([
            'id' => 'abc', 'user_id' => $user->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => time(),
        ]);

        $this->assertSame(0, Artisan::call('intrawatch:user:reset-password', ['email' => 'ada@example.com', '--password' => 'brand-new-password']));

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-password', $user->password));
        $this->assertNotSame($remember, $user->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());

        $event = AuditEvent::where('action', 'auth.password_reset')->sole();
        $this->assertSame('system', $event->actor_type);
        $this->assertSame((string) $user->id, $event->subject_id);
        $this->assertStringNotContainsString('brand-new-password', (string) json_encode($event->toArray()));

        $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'brand-new-password'], [
            'Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/login',
        ])->assertSuccessful();
    }

    public function test_recovery_commands_reject_unknown_users_and_weak_passwords(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->assertSame(1, Artisan::call('intrawatch:user:reset-2fa', ['email' => 'nobody@example.com']));
        $this->assertSame(1, Artisan::call('intrawatch:user:reset-password', ['email' => 'nobody@example.com', '--password' => 'long-enough-password']));
        $this->assertSame(1, Artisan::call('intrawatch:user:reset-password', ['email' => 'ada@example.com', '--password' => 'short']));
        $this->assertSame(0, AuditEvent::count());
    }
}
