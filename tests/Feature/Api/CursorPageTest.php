<?php

namespace Tests\Feature\Api;

use App\Enums\WorkspaceRole;
use App\Http\Pagination\CursorPage;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_cursor_with_bad_values_is_422_problem(): void
    {
        $user = $this->userWithWorkspaces(2);
        $encode = fn (array $p): string => rtrim(strtr(base64_encode((string) json_encode($p)), '+/', '-_'), '=');

        foreach ([['workspaces.id' => 'abc'], ['workspaces.id' => [1]], ['workspaces.id' => null]] as $params) {
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/workspaces?cursor='.$encode($params + ['_pointsToNextItems' => true]))
                ->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonStructure(['errors' => ['cursor']]);
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function encodeCursor(array $parameters): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($parameters + ['_pointsToNextItems' => true])), '+/', '-_'), '=');
    }

    /**
     * One audit event per page, newest first by timestamp.
     *
     * @return array{0: array<int, mixed>, 1: mixed}
     */
    private function timestampPage(?string $cursor): array
    {
        $request = Request::create('/', 'GET', $cursor === null ? ['limit' => 1] : ['limit' => 1, 'cursor' => $cursor]);
        $payload = CursorPage::from(AuditEvent::query()->orderByDesc('created_at')->orderByDesc('id'), $request)->getData(true);
        $this->assertIsArray($payload);
        $data = $payload['data'] ?? null;
        $meta = $payload['meta'] ?? null;
        $this->assertIsArray($data);
        $this->assertIsArray($meta);

        return [collect($data)->pluck('action')->values()->all(), $meta['next_cursor'] ?? null];
    }

    public function test_timestamp_ordered_cursor_round_trips(): void
    {
        Audit::record('first');
        $this->travel(1)->minutes();
        Audit::record('second');

        [$actions, $cursor] = $this->timestampPage(null);
        $this->assertSame(['second'], $actions);
        $this->assertIsString($cursor);

        [$actions, $cursor] = $this->timestampPage($cursor);
        $this->assertSame(['first'], $actions);
        $this->assertNull($cursor);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    #[DataProvider('malformedTimestampCursors')]
    public function test_malformed_timestamp_cursor_is_rejected(array $parameters): void
    {
        Audit::record('only');

        try {
            $this->timestampPage($this->encodeCursor($parameters));
            $this->fail('A malformed timestamp cursor must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cursor', $e->errors());
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function malformedTimestampCursors(): array
    {
        return [
            'text' => [['created_at' => 'yesterday-ish', 'id' => 1]],
            'sql' => [['created_at' => "2026-01-01' OR 1=1 --", 'id' => 1]],
            'bad id' => [['created_at' => '2026-01-01 10:00:00', 'id' => 'x']],
            'missing' => [['id' => 1]],
        ];
    }

    public function test_malformed_timestamp_cursor_is_a_422_problem_over_http(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $owner->workspaces()->attach($workspace, ['role' => WorkspaceRole::Owner->value]);

        // The audit list orders by id; a non-integer id in the cursor is rejected the same way.
        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/workspaces/{$workspace->slug}/audit?cursor=".$this->encodeCursor(['id' => '2026-01-01']))
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonStructure(['errors' => ['cursor']]);
    }
}
