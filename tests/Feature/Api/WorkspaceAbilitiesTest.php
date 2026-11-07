<?php

namespace Tests\Feature\Api;

use App\Enums\WorkspaceRole;
use App\Http\Resources\WorkspaceAbilities;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WorkspaceAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    private const ABILITIES = [
        'view', 'update', 'delete', 'viewMembers', 'manageMembers', 'viewAudit', 'viewMonitors', 'manageMonitors',
        'viewNotifications', 'manageNotifications', 'viewIncidents', 'manageIncidents', 'viewStatusPages',
        'manageStatusPages', 'manageCustomDomains',
    ];

    /**
     * @param  list<string>  $abilities
     */
    private function mint(User $user, Workspace $workspace, array $abilities): string
    {
        $token = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/workspaces/{$workspace->slug}/tokens", ['name' => 'ci', 'abilities' => $abilities])
            ->assertCreated()
            ->json('token');
        $this->assertIsString($token);

        $this->app['auth']->forgetGuards();

        return $token;
    }

    public function test_me_lists_channel_and_abilities_per_workspace(): void
    {
        $owned = Workspace::factory()->create(['name' => 'A owned']);
        $viewed = Workspace::factory()->create(['name' => 'B viewed']);
        $user = User::factory()->memberOf($owned, WorkspaceRole::Owner)->create();
        $user->workspaces()->attach($viewed, ['role' => WorkspaceRole::Viewer->value]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.workspaces.0.id', $owned->public_id)
            ->assertJsonPath('data.workspaces.0.channel', 'workspace.'.$owned->id)
            ->assertJsonPath('data.workspaces.0.timezone', $owned->fresh()?->timezone)
            ->assertJsonPath('data.workspaces.1.id', $viewed->public_id)
            ->assertJsonPath('data.workspaces.1.channel', 'workspace.'.$viewed->id)
            ->assertJsonPath('data.workspaces.1.can.viewMonitors', true)
            ->assertJsonPath('data.workspaces.1.can.manageMonitors', false)
            ->assertJsonPath('data.workspaces.1.can.update', false);

        foreach (self::ABILITIES as $ability) {
            $response->assertJsonPath('data.workspaces.0.can.'.$ability, true);
        }
        $response->assertJsonCount(count(self::ABILITIES), 'data.workspaces.0.can');
    }

    public function test_token_caller_abilities_are_intersected_with_token(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Owner)->create();
        $token = $this->mint($owner, $workspace, ['monitors:read']);

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.workspaces.0.channel', 'workspace.'.$workspace->id)
            ->assertJsonPath('data.workspaces.0.timezone', $workspace->fresh()?->timezone)
            ->assertJsonPath('data.workspaces.0.can.viewMonitors', true)
            ->assertJsonPath('data.workspaces.0.can.manageMonitors', false)
            ->assertJsonPath('data.workspaces.0.can.view', false)
            ->assertJsonPath('data.workspaces.0.can.update', false);
    }

    public function test_token_without_monitor_read_gets_no_channel(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Owner)->create();
        $token = $this->mint($owner, $workspace, ['workspace:read']);

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.workspaces.0.channel', null)
            ->assertJsonPath('data.workspaces.0.can.view', true)
            ->assertJsonPath('data.workspaces.0.can.viewMonitors', false);
    }

    public function test_workspace_index_store_and_update_return_channel_and_can(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();

        $this->actingAs($editor, 'sanctum')->getJson('/api/v1/workspaces')
            ->assertOk()
            ->assertJsonPath('data.0.channel', 'workspace.'.$workspace->id)
            ->assertJsonPath('data.0.can.manageMonitors', true)
            ->assertJsonPath('data.0.can.update', false);

        $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/workspaces/{$workspace->slug}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.channel', 'workspace.'.$workspace->id)
            ->assertJsonPath('data.can.update', true)
            ->assertJsonPath('data.can.delete', false);

        $this->actingAs($editor, 'sanctum')->postJson('/api/v1/workspaces', ['name' => 'Fresh'])
            ->assertCreated()
            ->assertJsonPath('data.can.delete', true);
    }

    public function test_abilities_helper_follows_the_policy_for_every_role(): void
    {
        $workspace = Workspace::factory()->create();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();

        $can = WorkspaceAbilities::for($viewer, $workspace);

        $this->assertSame(self::ABILITIES, array_keys($can));
        $this->assertTrue($can['view']);
        $this->assertFalse($can['manageCustomDomains']);
    }

    public function test_reset_link_points_to_admin(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email], ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/login'])
            ->assertSuccessful();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $mail = $notification->toMail($user);
            $url = $mail instanceof MailMessage ? (string) $mail->actionUrl : '';

            return str_starts_with($url, rtrim(config()->string('app.url'), '/').'/admin/reset-password/')
                && str_contains($url, '?email='.urlencode($user->email));
        });
    }
}
