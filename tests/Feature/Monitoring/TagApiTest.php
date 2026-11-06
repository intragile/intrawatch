<?php

namespace Tests\Feature\Monitoring;

use App\Enums\WorkspaceRole;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsWorkspaceIsolation;
use Tests\TestCase;

class TagApiTest extends TestCase
{
    use AssertsWorkspaceIsolation;
    use RefreshDatabase;

    private Workspace $ws;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ws = Workspace::factory()->create();
        $this->editor = User::factory()->memberOf($this->ws, WorkspaceRole::Editor)->create();
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/workspaces/{$this->ws->slug}/tags{$path}";
    }

    public function test_tag_crud_with_audit(): void
    {
        $id = $this->actingAs($this->editor)->postJson($this->url(), ['name' => 'prod', 'color' => '#FF0000'])
            ->assertCreated()->assertJsonPath('data.name', 'prod')->assertJsonPath('data.color', '#ff0000')->json('data.id');
        $this->assertIsInt($id);

        $this->actingAs($this->editor)->postJson($this->url(), ['name' => 'prod', 'color' => '#00ff00'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->actingAs($this->editor)->postJson($this->url(), ['name' => 'x', 'color' => 'red'])
            ->assertUnprocessable()->assertJsonValidationErrors(['color']);

        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['name' => 'production'])
            ->assertOk()->assertJsonPath('data.name', 'production')->assertJsonPath('data.color', '#ff0000');

        $this->actingAs($this->editor)->getJson($this->url())
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'production')->assertJsonStructure(['meta' => ['next_cursor', 'limit']]);

        $this->actingAs($this->editor)->deleteJson($this->url("/{$id}"))->assertNoContent();
        $this->assertDatabaseMissing('tags', ['id' => $id]);

        foreach (['tag.created', 'tag.updated', 'tag.deleted'] as $action) {
            $this->assertDatabaseHas('audit_events', ['action' => $action]);
        }
    }

    public function test_viewer_reads_but_cannot_write(): void
    {
        $viewer = User::factory()->memberOf($this->ws, WorkspaceRole::Viewer)->create();
        $this->actingAs($viewer)->getJson($this->url())->assertOk();
        $this->actingAs($viewer)->postJson($this->url(), ['name' => 'a'])->assertForbidden();
    }

    public function test_tag_endpoints_are_workspace_isolated(): void
    {
        [$actor, $own, $foreign] = $this->crossWorkspaceFixture();
        $tag = Tag::factory()->for($foreign)->create();
        foreach (['PATCH', 'DELETE'] as $verb) {
            $this->assertCrossWorkspaceNotFound($actor, $verb, "/api/v1/workspaces/{$own->slug}/tags/{$tag->id}", ['name' => 'x']);
        }
        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    }
}
