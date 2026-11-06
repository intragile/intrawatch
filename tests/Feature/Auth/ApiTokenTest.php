<?php

namespace Tests\Feature\Auth;

use App\Enums\TokenAbility;
use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    private function url(Workspace $workspace, string $path): string
    {
        return "/api/v1/workspaces/{$workspace->slug}{$path}";
    }

    /**
     * @param  list<string>  $abilities
     * @param  array<string, string>  $extra
     */
    private function mint(User $user, Workspace $workspace, array $abilities, array $extra = []): string
    {
        $response = $this->actingAs($user, 'sanctum')
            ->postJson($this->url($workspace, '/tokens'), ['name' => 'ci', 'abilities' => $abilities] + $extra)
            ->assertCreated();

        $token = $response->json('token');
        $this->assertIsString($token);

        return $token;
    }

    private function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_token_is_bound_to_its_workspace_and_abilities(): void
    {
        $a = Workspace::factory()->create();
        $b = Workspace::factory()->create();
        $owner = User::factory()->memberOf($a, WorkspaceRole::Owner)->create();
        $owner->workspaces()->attach($b, ['role' => WorkspaceRole::Owner->value]);

        $token = $this->mint($owner, $a, ['workspace:read']);

        $this->asToken($token)->getJson($this->url($a, '/members'))->assertOk();
        $this->asToken($token)->getJson($this->url($b, '/members'))->assertNotFound();
        $this->asToken($token)->patchJson($this->url($a, ''), ['name' => 'Renamed'])->assertForbidden();
    }

    public function test_token_with_ability_can_use_it(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Owner)->create();

        $token = $this->mint($owner, $workspace, ['workspace:manage']);

        $this->asToken($token)->patchJson($this->url($workspace, ''), ['name' => 'Renamed'])->assertOk();
    }

    public function test_session_request_passes_ability_check(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Owner)->create();

        $this->actingAs($owner, 'sanctum')
            ->patchJson($this->url($workspace, ''), ['name' => 'Renamed'])
            ->assertOk();
    }

    public function test_viewer_cannot_mint_manage_token(): void
    {
        $workspace = Workspace::factory()->create();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();

        $this->actingAs($viewer, 'sanctum')
            ->postJson($this->url($workspace, '/tokens'), ['name' => 'x', 'abilities' => ['workspace:manage']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('abilities');

        $this->actingAs($viewer, 'sanctum')
            ->postJson($this->url($workspace, '/tokens'), ['name' => 'x', 'abilities' => ['workspace:read']])
            ->assertCreated();
    }

    public function test_unknown_and_missing_abilities_are_rejected(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Owner)->create();

        foreach ([['nope'], []] as $abilities) {
            $this->actingAs($owner, 'sanctum')
                ->postJson($this->url($workspace, '/tokens'), ['name' => 'x', 'abilities' => $abilities])
                ->assertUnprocessable();
        }
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_token_dies_with_membership(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Owner)->create();
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();
        $token = $this->mint($admin, $workspace, ['workspace:read']);

        $this->asToken($token)->getJson($this->url($workspace, '/members'))->assertOk();

        Membership::where('user_id', $admin->id)->delete();

        $this->asToken($token)->getJson($this->url($workspace, '/members'))->assertNotFound();
        $this->assertTrue($owner->exists);
    }

    public function test_token_cannot_exceed_creators_current_role(): void
    {
        $workspace = Workspace::factory()->create();
        User::factory()->memberOf($workspace, WorkspaceRole::Owner)->create();
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();
        $token = $this->mint($admin, $workspace, ['workspace:manage']);

        Membership::where('user_id', $admin->id)->update(['role' => WorkspaceRole::Viewer->value]);

        $this->asToken($token)->patchJson($this->url($workspace, ''), ['name' => 'Renamed'])->assertForbidden();
    }

    public function test_plaintext_token_is_shown_once(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Owner)->create();

        $created = $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($workspace, '/tokens'), ['name' => 'ci', 'abilities' => ['workspace:read']])
            ->assertCreated()
            ->assertJsonStructure(['id', 'token']);

        $list = $this->actingAs($owner, 'sanctum')->getJson($this->url($workspace, '/tokens'))->assertOk();

        $items = $list->json('data');
        $this->assertIsArray($items);
        $this->assertCount(1, $items);
        $this->assertIsArray($items[0]);
        $this->assertArrayNotHasKey('token', $items[0]);
        $this->assertSame($created->json('id'), $items[0]['id']);
        $plain = $created->json('token');
        $this->assertIsString($plain);
        $this->assertStringNotContainsString($plain, (string) $list->getContent());
    }

    public function test_list_only_shows_own_tokens_in_this_workspace(): void
    {
        $workspace = Workspace::factory()->create();
        $other = Workspace::factory()->create();
        $me = User::factory()->memberOf($workspace, WorkspaceRole::Owner)->create();
        $me->workspaces()->attach($other, ['role' => 'owner']);
        $colleague = User::factory()->memberOf($workspace, WorkspaceRole::Owner)->create();

        $this->mint($me, $workspace, ['workspace:read']);
        $this->mint($me, $other, ['workspace:read']);
        $this->mint($colleague, $workspace, ['workspace:read']);

        $this->actingAs($me, 'sanctum')->getJson($this->url($workspace, '/tokens'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_token_can_be_revoked_only_by_its_owner(): void
    {
        $workspace = Workspace::factory()->create();
        $me = User::factory()->memberOf($workspace, WorkspaceRole::Owner)->create();
        $colleague = User::factory()->memberOf($workspace, WorkspaceRole::Owner)->create();
        $this->mint($colleague, $workspace, ['workspace:read']);
        $secret = $this->mint($me, $workspace, ['workspace:read']);
        $mine = $me->tokens()->firstOrFail();
        $theirs = $colleague->tokens()->firstOrFail();

        $this->actingAs($me, 'sanctum')->deleteJson($this->url($workspace, "/tokens/{$theirs->id}"))->assertNotFound();
        $this->actingAs($me, 'sanctum')->deleteJson($this->url($workspace, "/tokens/{$mine->id}"))->assertNoContent();

        $this->asToken($secret)->getJson($this->url($workspace, '/members'))->assertUnauthorized();
        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_expired_token_is_rejected_and_expiry_must_be_in_the_future(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Owner)->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($workspace, '/tokens'), [
                'name' => 'x', 'abilities' => ['workspace:read'], 'expires_at' => now()->subDay()->toIso8601String(),
            ])->assertUnprocessable();

        $token = $this->mint($owner, $workspace, ['workspace:read'], ['expires_at' => now()->addHour()->toIso8601String()]);
        $this->asToken($token)->getJson($this->url($workspace, '/members'))->assertOk();

        $this->travel(2)->hours();
        $this->asToken($token)->getJson($this->url($workspace, '/members'))->assertUnauthorized();
    }

    public function test_token_cannot_manage_tokens(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Owner)->create();
        $token = $this->mint($owner, $workspace, ['workspace:read', 'workspace:manage', 'members:manage']);

        $this->asToken($token)->getJson($this->url($workspace, '/tokens'))->assertForbidden();
        $this->asToken($token)
            ->postJson($this->url($workspace, '/tokens'), ['name' => 'x', 'abilities' => ['workspace:read']])
            ->assertForbidden();
    }

    public function test_members_manage_ability_gates_member_writes(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Owner)->create();
        $other = User::factory()->create();
        $readOnly = $this->mint($owner, $workspace, ['workspace:read']);
        $manager = $this->mint($owner, $workspace, ['members:manage']);

        $this->asToken($readOnly)
            ->postJson($this->url($workspace, '/members'), ['email' => $other->email, 'role' => 'viewer'])
            ->assertForbidden();
        $this->asToken($manager)
            ->postJson($this->url($workspace, '/members'), ['email' => $other->email, 'role' => 'viewer'])
            ->assertCreated();
    }

    public function test_every_ability_declares_a_minimum_role(): void
    {
        $this->assertSame(WorkspaceRole::Viewer, TokenAbility::WorkspaceRead->minimumRole());
        $this->assertSame(WorkspaceRole::Admin, TokenAbility::WorkspaceManage->minimumRole());
        $this->assertSame(WorkspaceRole::Admin, TokenAbility::MembersManage->minimumRole());
    }

    public function test_current_workspace_binding_is_reset_between_requests(): void
    {
        $workspace = Workspace::factory()->create();
        $first = $this->app->make(CurrentWorkspace::class);
        $first->set($workspace, WorkspaceRole::Owner);
        $this->assertSame($first, $this->app->make(CurrentWorkspace::class));

        $this->app->forgetScopedInstances();

        $second = $this->app->make(CurrentWorkspace::class);
        $this->assertNotSame($first, $second);
        $this->expectException(\LogicException::class);
        $second->get();
    }
}
