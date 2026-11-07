<?php

namespace Tests\Feature\Monitoring;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class BroadcastChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The null broadcaster accepts every channel; a signing driver exercises the channel callbacks.
        // Channels register on the driver that is the default at boot, so they are loaded again here.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        require base_path('routes/channels.php');
    }

    /**
     * @return TestResponse<Response>
     */
    private function authorize(Workspace $workspace): TestResponse
    {
        return $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-workspace.{$workspace->id}",
        ]);
    }

    public function test_workspace_channel_authorizes_members_only(): void
    {
        $a = Workspace::factory()->create();
        $b = Workspace::factory()->create();
        $member = User::factory()->memberOf($a, WorkspaceRole::Viewer)->memberOf($b, WorkspaceRole::Viewer)->create();
        $outsider = User::factory()->create();

        $this->authorize($a)->assertUnauthorized();

        $this->actingAs($member)->authorize($a)->assertOk()->assertJsonStructure(['auth']);
        $this->actingAs($outsider)->authorize($a)->assertForbidden();

        $token = $member->createToken('ws-a');
        $token->accessToken->forceFill(['workspace_id' => $a->id])->save();

        $this->app['auth']->forgetGuards();
        $this->withToken($token->plainTextToken)->authorize($a)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token->plainTextToken)->authorize($b)->assertForbidden();
    }

    public function test_the_spa_cookie_session_authorizes_its_workspace_channel(): void
    {
        // A persistent session driver, with guards and session stores dropped between requests, so the
        // user can only come from the session cookie, as in a browser.
        config(['session.driver' => 'database']);
        $workspace = Workspace::factory()->create();
        $member = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();
        $stateful = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/admin/'];

        $login = $this->postJson('/api/v1/auth/login', ['email' => $member->email, 'password' => 'password'], $stateful)
            ->assertSuccessful();
        $cookie = $login->getCookie(config()->string('session.cookie'), false);
        $this->assertNotNull($cookie);

        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');

        $this->withCredentials()->withUnencryptedCookie($cookie->getName(), (string) $cookie->getValue())
            ->withHeaders($stateful)
            ->authorize($workspace)
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_a_token_needs_the_monitors_read_ability_to_listen(): void
    {
        $workspace = Workspace::factory()->create();
        $member = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();

        $audit = $member->createToken('audit-only', ['audit:read']);
        $audit->accessToken->forceFill(['workspace_id' => $workspace->id])->save();
        $this->withToken($audit->plainTextToken)->authorize($workspace)->assertForbidden();

        $monitors = $member->createToken('dashboard', ['monitors:read']);
        $monitors->accessToken->forceFill(['workspace_id' => $workspace->id])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($monitors->plainTextToken)->authorize($workspace)->assertOk();
    }
}
