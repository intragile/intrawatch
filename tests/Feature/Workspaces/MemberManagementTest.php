<?php

namespace Tests\Feature\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MemberManagementTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
    }

    private function member(WorkspaceRole $role): User
    {
        return User::factory()->memberOf($this->workspace, $role)->create();
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/workspaces/{$this->workspace->slug}/members{$suffix}";
    }

    public function test_viewer_can_list_members(): void
    {
        $viewer = $this->member(WorkspaceRole::Viewer);
        $this->member(WorkspaceRole::Owner);

        $this->actingAs($viewer, 'sanctum')->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'role']], 'meta' => ['next_cursor', 'limit']]);
    }

    public function test_member_list_is_cursor_paginated(): void
    {
        $viewer = $this->member(WorkspaceRole::Viewer);
        $this->member(WorkspaceRole::Owner);
        $this->member(WorkspaceRole::Editor);

        $first = $this->actingAs($viewer, 'sanctum')->getJson($this->url().'?limit=2')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.limit', 2);
        $cursor = $first->json('meta.next_cursor');
        $this->assertIsString($cursor);

        $second = $this->actingAs($viewer, 'sanctum')->getJson($this->url().'?limit=2&cursor='.urlencode($cursor))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.next_cursor', null);

        $ids = collect((array) $first->json('data'))->merge((array) $second->json('data'))->pluck('id');
        $this->assertSame(3, $ids->unique()->count());
    }

    public function test_editor_cannot_add_members(): void
    {
        $editor = $this->member(WorkspaceRole::Editor);
        $other = User::factory()->create();

        $this->actingAs($editor, 'sanctum')
            ->postJson($this->url(), ['email' => $other->email, 'role' => 'viewer'])
            ->assertForbidden();
    }

    public function test_admin_adds_existing_user(): void
    {
        $admin = $this->member(WorkspaceRole::Admin);
        $other = User::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->url(), ['email' => $other->email, 'role' => 'editor'])
            ->assertCreated()
            ->assertJsonPath('data.role', 'editor');

        $this->assertSame(WorkspaceRole::Editor, $other->roleIn($this->workspace));
    }

    public function test_adding_unknown_email_or_existing_member_is_rejected(): void
    {
        $admin = $this->member(WorkspaceRole::Admin);

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->url(), ['email' => 'ghost@example.test', 'role' => 'viewer'])
            ->assertUnprocessable();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->url(), ['email' => $admin->email, 'role' => 'viewer'])
            ->assertUnprocessable();
    }

    public function test_member_email_lookup_is_case_insensitive(): void
    {
        $admin = $this->member(WorkspaceRole::Admin);
        $other = User::factory()->create(['email' => 'mixed.case@example.com']);

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->url(), ['email' => '  Mixed.Case@EXAMPLE.com ', 'role' => 'viewer'])
            ->assertCreated()
            ->assertJsonPath('data.id', $other->id);
    }

    public function test_last_owner_guard_locks_owner_rows_in_id_order(): void
    {
        $owner = $this->member(WorkspaceRole::Owner);
        $this->member(WorkspaceRole::Owner);

        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->actingAs($owner, 'sanctum')->patchJson($this->url("/{$owner->id}"), ['role' => 'admin'])->assertOk();

        $lock = array_values(array_filter(
            $statements,
            fn (string $sql): bool => preg_match('/^select .* from [`"]memberships[`"] where .*[`"]role[`"] = \?/', $sql) === 1,
        ));
        $this->assertNotEmpty($lock, 'The owner rows were not queried.');
        // A stable lock order prevents deadlocks between concurrent demotions.
        $this->assertMatchesRegularExpression('/order by [`"]?id[`"]? asc/i', $lock[0]);
    }

    public function test_invalid_role_is_rejected(): void
    {
        $admin = $this->member(WorkspaceRole::Admin);
        $other = User::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->url(), ['email' => $other->email, 'role' => 'god'])
            ->assertUnprocessable();
    }

    public function test_only_owner_can_add_an_owner(): void
    {
        $admin = $this->member(WorkspaceRole::Admin);
        $owner = $this->member(WorkspaceRole::Owner);
        $other = User::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->url(), ['email' => $other->email, 'role' => 'owner'])
            ->assertForbidden();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url(), ['email' => $other->email, 'role' => 'owner'])
            ->assertCreated();
    }

    public function test_only_owner_can_grant_owner(): void
    {
        $admin = $this->member(WorkspaceRole::Admin);
        $owner = $this->member(WorkspaceRole::Owner);
        $target = $this->member(WorkspaceRole::Editor);

        $this->actingAs($admin, 'sanctum')
            ->patchJson($this->url("/{$target->id}"), ['role' => 'owner'])
            ->assertForbidden();

        $this->actingAs($owner, 'sanctum')
            ->patchJson($this->url("/{$target->id}"), ['role' => 'owner'])
            ->assertOk()
            ->assertJsonPath('data.role', 'owner');
    }

    public function test_admin_cannot_demote_or_remove_an_owner(): void
    {
        $admin = $this->member(WorkspaceRole::Admin);
        $this->member(WorkspaceRole::Owner);
        $secondOwner = $this->member(WorkspaceRole::Owner);

        $this->actingAs($admin, 'sanctum')
            ->patchJson($this->url("/{$secondOwner->id}"), ['role' => 'viewer'])
            ->assertForbidden();
        $this->actingAs($admin, 'sanctum')
            ->deleteJson($this->url("/{$secondOwner->id}"))
            ->assertForbidden();
    }

    public function test_admin_can_change_role_and_remove_member(): void
    {
        $admin = $this->member(WorkspaceRole::Admin);
        $target = $this->member(WorkspaceRole::Viewer);

        $this->actingAs($admin, 'sanctum')
            ->patchJson($this->url("/{$target->id}"), ['role' => 'editor'])
            ->assertOk();
        $this->assertSame(WorkspaceRole::Editor, $target->roleIn($this->workspace));

        $this->actingAs($admin, 'sanctum')->deleteJson($this->url("/{$target->id}"))->assertNoContent();
        $this->assertNull($target->roleIn($this->workspace));
    }

    public function test_unknown_member_gets_404(): void
    {
        $admin = $this->member(WorkspaceRole::Admin);
        $outsider = User::factory()->create();

        $this->actingAs($admin, 'sanctum')->deleteJson($this->url("/{$outsider->id}"))->assertNotFound();
        $this->actingAs($admin, 'sanctum')
            ->patchJson($this->url("/{$outsider->id}"), ['role' => 'viewer'])
            ->assertNotFound();
    }

    public function test_last_owner_cannot_be_removed_or_demoted(): void
    {
        $owner = $this->member(WorkspaceRole::Owner);

        $this->actingAs($owner, 'sanctum')->deleteJson($this->url("/{$owner->id}"))->assertUnprocessable();
        $this->actingAs($owner, 'sanctum')
            ->patchJson($this->url("/{$owner->id}"), ['role' => 'admin'])
            ->assertUnprocessable();
        $this->assertSame(WorkspaceRole::Owner, $owner->roleIn($this->workspace));

        $this->member(WorkspaceRole::Owner);

        $this->actingAs($owner, 'sanctum')
            ->patchJson($this->url("/{$owner->id}"), ['role' => 'admin'])
            ->assertOk();
        $this->assertSame(WorkspaceRole::Admin, $owner->roleIn($this->workspace));
    }

    public function test_owner_can_leave_when_another_owner_exists(): void
    {
        $owner = $this->member(WorkspaceRole::Owner);
        $this->member(WorkspaceRole::Owner);

        $this->actingAs($owner, 'sanctum')->deleteJson($this->url("/{$owner->id}"))->assertNoContent();
        $this->assertNull($owner->roleIn($this->workspace));
    }
}
