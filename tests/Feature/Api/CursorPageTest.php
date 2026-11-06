<?php

namespace Tests\Feature\Api;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CursorPageTest extends TestCase
{
    use RefreshDatabase;

    private function userWithWorkspaces(int $count): User
    {
        $user = User::factory()->create();
        foreach (Workspace::factory()->count($count)->create() as $workspace) {
            $user->workspaces()->attach($workspace, ['role' => WorkspaceRole::Viewer->value]);
        }

        return $user;
    }

    public function test_cursor_page_limits_and_cursors(): void
    {
        $user = $this->userWithWorkspaces(120);

        $ids = [];
        $url = '/api/v1/workspaces?limit=50';
        $pages = 0;
        do {
            $response = $this->actingAs($user, 'sanctum')->getJson($url)->assertOk()->assertJsonPath('meta.limit', 50);
            $pages++;
            $data = $response->json('data');
            $this->assertIsArray($data);
            $this->assertLessThanOrEqual(50, count($data));
            foreach ($data as $item) {
                $this->assertIsArray($item);
                $this->assertIsString($item['id']);
                $ids[] = $item['id'];
            }
            $cursor = $response->json('meta.next_cursor');
            $url = '/api/v1/workspaces?limit=50&cursor='.urlencode(is_string($cursor) ? $cursor : '');
        } while ($cursor !== null);

        $this->assertSame(3, $pages);
        $this->assertCount(120, array_unique($ids));
    }

    public function test_default_and_max_limit(): void
    {
        $user = $this->userWithWorkspaces(3);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/workspaces')
            ->assertOk()->assertJsonPath('meta.limit', 50)->assertJsonPath('meta.next_cursor', null);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/workspaces?limit=500')
            ->assertOk()->assertJsonPath('meta.limit', 200);
    }

    public function test_invalid_limit_or_cursor_is_422_problem(): void
    {
        $user = $this->userWithWorkspaces(1);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/workspaces?limit=abc')
            ->assertStatus(422)->assertJsonStructure(['errors' => ['limit']]);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/workspaces?limit=0')
            ->assertStatus(422)->assertJsonStructure(['errors' => ['limit']]);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/workspaces?cursor=garbage')
            ->assertStatus(422)->assertJsonStructure(['errors' => ['cursor']]);
        $tampered = rtrim(strtr(base64_encode('{"unrelated":1,"_pointsToNextItems":true}'), '+/', '-_'), '=');
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/workspaces?cursor='.$tampered)
            ->assertStatus(422)->assertJsonStructure(['errors' => ['cursor']]);
    }
}
