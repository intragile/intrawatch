<?php

namespace Tests\Feature\Monitoring;

use App\Enums\WorkspaceRole;
use App\Models\Monitor;
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
            ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.name', 'production')->assertJsonPath('data.color', '#ff0000');

        $this->actingAs($this->editor)->getJson($this->url())
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'production')->assertJsonStructure(['meta' => ['next_cursor', 'limit']]);

        $this->actingAs($this->editor)->deleteJson($this->url("/{$id}"))->assertNoContent();
        $this->assertDatabaseMissing('tags', ['id' => $id]);

        foreach (['tag.created', 'tag.updated', 'tag.deleted'] as $action) {
            $this->assertDatabaseHas('audit_events', ['action' => $action]);
        }
    }

    public function test_tag_payloads_carry_how_many_monitors_use_the_tag(): void
    {
        $used = Tag::factory()->for($this->ws)->create(['name' => 'used']);
        $unused = Tag::factory()->for($this->ws)->create(['name' => 'unused']);
        Monitor::factory()->for($this->ws)->http()->create()->tags()->attach($used, ['value' => 'eu']);
        // A paused monitor still carries the tag, so it counts.
        Monitor::factory()->for($this->ws)->http()->inactive()->create()->tags()->attach($used);
        Monitor::factory()->for($this->ws)->http()->create();

        $this->actingAs($this->editor)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.0.name', 'used')->assertJsonPath('data.0.monitors_count', 2)
            ->assertJsonPath('data.1.name', 'unused')->assertJsonPath('data.1.monitors_count', 0);

        $this->actingAs($this->editor)->patchJson($this->url("/{$used->id}"), ['color' => '#123456'])
            ->assertOk()->assertJsonPath('data.monitors_count', 2);
        $this->actingAs($this->editor)->patchJson($this->url("/{$unused->id}"), ['name' => 'spare'])
            ->assertOk()->assertJsonPath('data.monitors_count', 0);
        $this->actingAs($this->editor)->postJson($this->url(), ['name' => 'fresh'])
            ->assertCreated()->assertJsonPath('data.monitors_count', 0);
    }

    public function test_deleting_a_tag_detaches_it_from_its_monitors_and_keeps_them(): void
    {
        $tag = Tag::factory()->for($this->ws)->create();
        $other = Tag::factory()->for($this->ws)->create();
        $monitor = Monitor::factory()->for($this->ws)->http()->create();
        $monitor->tags()->attach($tag, ['value' => 'eu']);
        $monitor->tags()->attach($other);

        $this->actingAs($this->editor)->deleteJson($this->url("/{$tag->id}"))->assertNoContent();

        // The monitor_tag rows go with the tag through the foreign key cascade, on every supported database.
        $this->assertDatabaseMissing('monitor_tag', ['tag_id' => $tag->id]);
        $this->assertDatabaseHas('monitor_tag', ['monitor_id' => $monitor->id, 'tag_id' => $other->id]);
        $this->assertDatabaseHas('monitors', ['id' => $monitor->id]);
        $this->assertSame([$other->id], $monitor->tags()->pluck('tags.id')->all());
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
