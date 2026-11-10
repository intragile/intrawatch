<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\UserAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SessionManagementTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT = 'currentsessionidcurrentsessionidcurrentx';

    private const OTHER = 'othersessionidothersessionidothersession1';

    private const FIREFOX = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.5; rv:132.0) Gecko/20100101 Firefox/132.0';

    /** @var array<string, string> */
    private array $stateful = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/admin/account'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database']);
    }

    private function storeSession(string $id, User $owner, int $lastActivity, ?string $userAgent = 'curl/8'): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $owner->id, 'ip_address' => '10.0.0.7', 'user_agent' => $userAgent, 'payload' => base64_encode(serialize([])), 'last_activity' => $lastActivity,
        ]);
    }

    /**
     * @param  array<string, string>  $body
     * @return TestResponse<Response>
     */
    private function send(User $user, string $method, string $uri, array $body = []): TestResponse
    {
        return $this->actingAs($user)
            ->withCredentials()->withCookie(config()->string('session.cookie'), self::CURRENT)
            ->json($method, $uri, $body, $this->stateful + ['User-Agent' => self::FIREFOX]);
    }

    public function test_list_marks_the_current_session_and_hides_raw_ids(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $this->storeSession(self::CURRENT, $user, time() - 30);
        $this->storeSession(self::OTHER, $user, time() - 600, 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1');
        $this->storeSession('expiredsessionidexpiredsessionidexpired1', $user, time() - (config()->integer('session.lifetime') * 60) - 60);
        $this->storeSession('strangersessionidstrangersessionidstrang', $stranger, time());

        $response = $this->send($user, 'GET', '/api/v1/me/sessions')->assertOk();

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(2, $data);
        $response->assertJsonPath('data.0.current', true)
            ->assertJsonPath('data.0.browser', 'Firefox')
            ->assertJsonPath('data.0.platform', 'macOS')
            ->assertJsonPath('data.1.current', false)
            ->assertJsonPath('data.1.ip_address', '10.0.0.7')
            ->assertJsonPath('data.1.browser', 'Safari')
            ->assertJsonPath('data.1.platform', 'iOS');
        $this->assertNotSame($response->json('data.0.id'), $response->json('data.1.id'));
        foreach ([self::CURRENT, self::OTHER, 'strangersessionid'] as $raw) {
            $this->assertStringNotContainsString($raw, $response->getContent() ?: '');
        }
    }

    public function test_sign_out_others_requires_the_password(): void
    {
        $user = User::factory()->create();
        $this->storeSession(self::OTHER, $user, time());

        $this->send($user, 'POST', '/api/v1/me/sessions/sign-out-others', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->send($user, 'POST', '/api/v1/me/sessions/sign-out-others', ['password' => 'wrong-password'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);

        $this->assertSame(1, DB::table('sessions')->where('id', self::OTHER)->count());
        $this->assertDatabaseMissing('audit_events', ['action' => 'auth.other_sessions_signed_out']);
    }

    public function test_sign_out_others_keeps_this_session_and_api_tokens(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $remember = $user->remember_token;
        $user->createToken('ci', ['workspace:read']);
        $this->storeSession(self::CURRENT, $user, time());
        $this->storeSession(self::OTHER, $user, time());
        $this->storeSession('strangersessionidstrangersessionidstrang', $stranger, time());

        $this->send($user, 'POST', '/api/v1/me/sessions/sign-out-others', ['password' => 'password'])->assertNoContent();

        $this->assertSame([self::CURRENT], DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $stranger->id)->count());
        $this->assertNotSame($remember, $user->refresh()->remember_token);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.other_sessions_signed_out', 'actor_user_id' => $user->id, 'workspace_id' => null]);
    }

    public function test_api_tokens_cannot_see_or_end_sessions(): void
    {
        $user = User::factory()->create();
        $plain = $user->createToken('ci', ['workspace:read'])->plainTextToken;

        $this->withToken($plain)->getJson('/api/v1/me/sessions')->assertForbidden();
        $this->withToken($plain)->postJson('/api/v1/me/sessions/sign-out-others', ['password' => 'password'])->assertForbidden();
    }

    public function test_user_agent_is_reduced_to_browser_and_platform(): void
    {
        $this->assertSame(['browser' => 'Edge', 'platform' => 'Windows'], UserAgent::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0'));
        $this->assertSame(['browser' => 'Chrome', 'platform' => 'Android'], UserAgent::parse('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36'));
        $this->assertSame(['browser' => null, 'platform' => null], UserAgent::parse('curl/8.7.1'));
        $this->assertSame(['browser' => null, 'platform' => null], UserAgent::parse(null));
    }
}
