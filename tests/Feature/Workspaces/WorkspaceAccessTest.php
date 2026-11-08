<?php

namespace Tests\Feature\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/v1/workspaces')->assertUnauthorized();
    }

    public function test_non_member_gets_404_not_403(): void
    {
        [$a, $b] = [Workspace::factory()->create(), Workspace::factory()->create()];
        $user = User::factory()->memberOf($a, WorkspaceRole::Owner)->create();

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/workspaces/{$b->slug}/members")->assertNotFound();
    }

    public function test_unknown_workspace_gets_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/workspaces/nope/members')->assertNotFound();
    }

    public function test_index_lists_only_own_workspaces_with_role(): void
    {
        $mine = Workspace::factory()->create();
        Workspace::factory()->create();
        $user = User::factory()->memberOf($mine, WorkspaceRole::Editor)->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/workspaces')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $mine->slug)
            ->assertJsonPath('data.0.role', 'editor');
    }

    public function test_creator_becomes_owner(): void
    {
        config(['intrawatch.workspaces.creators' => 'anyone']);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/workspaces', ['name' => 'Acme'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'acme')
            ->assertJsonPath('data.role', 'owner');

        $workspace = Workspace::where('slug', 'acme')->firstOrFail();
        $this->assertSame(WorkspaceRole::Owner, $user->roleIn($workspace));
        $this->assertNotEmpty($workspace->public_id);
    }

    public function test_slug_gets_numeric_suffix_when_taken(): void
    {
        config(['intrawatch.workspaces.creators' => 'anyone']);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/workspaces', ['name' => 'Acme'])->assertCreated();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/workspaces', ['name' => 'Acme'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'acme-2');
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/workspaces', ['name' => 'Acme'])
            ->assertJsonPath('data.slug', 'acme-3');
    }

    public function test_create_requires_name(): void
    {
        config(['intrawatch.workspaces.creators' => 'anyone']);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/workspaces', [])->assertUnprocessable();
    }

    /**
     * @return array<string, array{string, WorkspaceRole|null, int}>
     */
    public static function creatorModes(): array
    {
        return [
            'owners: viewer elsewhere' => ['owners', WorkspaceRole::Viewer, 403],
            'owners: admin elsewhere' => ['owners', WorkspaceRole::Admin, 403],
            'owners: no workspace' => ['owners', null, 403],
            'owners: owner elsewhere' => ['owners', WorkspaceRole::Owner, 201],
            'anyone: viewer' => ['anyone', WorkspaceRole::Viewer, 201],
            'anyone: no workspace' => ['anyone', null, 201],
            'none: owner' => ['none', WorkspaceRole::Owner, 403],
            'none: viewer' => ['none', WorkspaceRole::Viewer, 403],
            'unknown value behaves like owners' => ['everybody', WorkspaceRole::Viewer, 403],
        ];
    }

    #[DataProvider('creatorModes')]
    public function test_workspace_creation_follows_the_creators_setting(string $mode, ?WorkspaceRole $role, int $status): void
    {
        config(['intrawatch.workspaces.creators' => $mode]);
        $factory = User::factory();
        if ($role !== null) {
            $factory = $factory->memberOf(Workspace::factory()->create(), $role);
        }
        $user = $factory->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.can_create_workspace', $status === 201);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/workspaces', ['name' => 'Fresh'])->assertStatus($status);
        $this->assertSame($status === 201, Workspace::where('name', 'Fresh')->exists());
    }

    public function test_default_setting_is_owners(): void
    {
        $this->assertSame('owners', config('intrawatch.workspaces.creators'));
        $viewer = User::factory()->memberOf(Workspace::factory()->create(), WorkspaceRole::Viewer)->create();

        $this->actingAs($viewer, 'sanctum')->postJson('/api/v1/workspaces', ['name' => 'Fresh'])->assertForbidden();
    }

    public function test_tokens_never_create_workspaces(): void
    {
        config(['intrawatch.workspaces.creators' => 'anyone']);
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Owner)->create();
        $plain = $owner->createToken('ci', ['workspace:manage'])->plainTextToken;

        $this->withToken($plain)->postJson('/api/v1/workspaces', ['name' => 'Fresh'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/me')->assertOk()->assertJsonMissingPath('data.can_create_workspace');
    }

    public function test_viewer_cannot_rename_workspace(): void
    {
        $workspace = Workspace::factory()->create(['name' => 'Old']);
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();

        $this->actingAs($viewer, 'sanctum')
            ->patchJson("/api/v1/workspaces/{$workspace->slug}", ['name' => 'New'])
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/workspaces/{$workspace->slug}", ['name' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New');

        $this->assertSame('New', $workspace->fresh()?->name);
    }

    public function test_workspace_timezone_is_validated_as_an_iana_zone(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();
        $url = "/api/v1/workspaces/{$workspace->slug}";

        $this->actingAs($admin, 'sanctum')
            ->patchJson($url, ['name' => $workspace->name, 'timezone' => 'Europe/Budapest'])
            ->assertOk()
            ->assertJsonPath('data.timezone', 'Europe/Budapest');
        $this->assertSame('Europe/Budapest', $workspace->refresh()->timezone);
        $this->assertDatabaseHas('audit_events', ['action' => 'workspace.updated', 'workspace_id' => $workspace->id]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson($url, ['name' => $workspace->name, 'timezone' => 'Mars/Base'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('timezone');
        $this->assertSame('Europe/Budapest', $workspace->refresh()->timezone);

        // Omitting the timezone leaves it untouched.
        $this->actingAs($admin, 'sanctum')->patchJson($url, ['name' => 'Renamed'])->assertOk()
            ->assertJsonPath('data.timezone', 'Europe/Budapest');
    }

    public function test_only_owner_can_delete_workspace(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Owner)->create();

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/workspaces/{$workspace->slug}")->assertForbidden();
        $this->actingAs($owner, 'sanctum')->deleteJson("/api/v1/workspaces/{$workspace->slug}")->assertNoContent();

        $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);
        $this->assertDatabaseCount('memberships', 0);
    }

    public function test_role_hierarchy(): void
    {
        $this->assertTrue(WorkspaceRole::Owner->atLeast(WorkspaceRole::Admin));
        $this->assertTrue(WorkspaceRole::Admin->atLeast(WorkspaceRole::Admin));
        $this->assertFalse(WorkspaceRole::Editor->atLeast(WorkspaceRole::Admin));
        $this->assertTrue(WorkspaceRole::Editor->atLeast(WorkspaceRole::Viewer));
        $this->assertFalse(WorkspaceRole::Viewer->atLeast(WorkspaceRole::Editor));
    }
}
