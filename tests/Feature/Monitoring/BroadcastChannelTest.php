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
