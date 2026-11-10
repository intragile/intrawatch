<?php

namespace Tests\Feature\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\AssertsWorkspaceIsolation;
use Tests\TestCase;
use ValueError;

class TenantScopingTest extends TestCase
{
    use AssertsWorkspaceIsolation;
    use RefreshDatabase;

    public function test_member_of_another_workspace_is_not_reachable_through_own_workspace(): void
    {
        [$actor, $own, $foreign] = $this->crossWorkspaceFixture();
        $stranger = User::factory()->memberOf($foreign, WorkspaceRole::Viewer)->create();

        $this->assertCrossWorkspaceNotFound($actor, 'PATCH', "/api/v1/workspaces/{$own->slug}/members/{$stranger->id}", ['role' => 'editor']);
        $this->assertCrossWorkspaceNotFound($actor, 'DELETE', "/api/v1/workspaces/{$own->slug}/members/{$stranger->id}");
        $this->assertCrossWorkspaceNotFound($actor, 'GET', "/api/v1/workspaces/{$foreign->slug}/members");
        $this->assertCrossWorkspaceNotFound($actor, 'DELETE', "/api/v1/workspaces/{$foreign->slug}/members/{$stranger->id}");

        $this->assertSame(WorkspaceRole::Viewer, $stranger->roleIn($foreign));
    }

    public function test_token_of_another_workspace_cannot_be_revoked(): void
    {
        [$actor, $own, $foreign] = $this->crossWorkspaceFixture();
        $foreign->members()->attach($actor, ['role' => WorkspaceRole::Owner->value]);
        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/v1/workspaces/{$foreign->slug}/tokens", ['name' => 'ci', 'abilities' => ['workspace:read']])
            ->assertCreated();
        $tokenId = $actor->tokens()->value('id');
        $this->assertIsInt($tokenId);

        $this->assertCrossWorkspaceNotFound($actor, 'DELETE', "/api/v1/workspaces/{$own->slug}/tokens/{$tokenId}");
        $this->assertSame(1, $actor->tokens()->count());
    }

    public function test_non_numeric_ids_are_404_problems(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Owner)->create();

        foreach ([
            ['DELETE', '/members/abc'],
            ['PATCH', '/members/abc'],
            ['DELETE', '/members/1abc'],
            ['DELETE', '/tokens/abc'],
        ] as [$method, $path]) {
            $this->actingAs($owner, 'sanctum')
                ->json($method, "/api/v1/workspaces/{$workspace->slug}{$path}", ['role' => 'viewer'])
                ->assertNotFound()
                ->assertHeader('Content-Type', 'application/problem+json');
        }
    }

    public function test_every_child_route_parameter_is_constrained(): void
    {
        $unconstrained = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $wheres = $route->wheres;
            foreach ($route->parameterNames() as $parameter) {
                // A push token is a credential, not a model id; it only has to be alphanumeric.
                if ($parameter === 'token' && str_starts_with($route->uri(), 'api/v1/push/') && ($wheres[$parameter] ?? null) === '[A-Za-z0-9]+') {
                    continue;
                }
                // Imports are keyed by ULID; the constraint is Laravel's whereUlid() pattern.
                if ($parameter === 'import' && ($wheres[$parameter] ?? null) === '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}') {
                    continue;
                }
                // A changelog version is a release number from CHANGELOG.md, not a model id; the package answers 404 for an unknown one.
                if ($parameter === 'version' && $route->uri() === 'api/v1/changelog/{version}') {
                    continue;
                }
                // A session handle is an HMAC of the user's own session id, not a model id; only that user's rows are matched.
                if ($parameter === 'id' && $route->uri() === 'api/v1/me/sessions/{id}' && ($wheres[$parameter] ?? null) === '[0-9a-f]{32}') {
                    continue;
                }
                if (is_string($parameter) && $parameter !== 'workspace' && ($wheres[$parameter] ?? null) !== '[0-9]+') {
                    $unconstrained[] = $route->uri().' {'.$parameter.'}';
                }
            }
        }

        $this->assertSame([], $unconstrained, 'Route parameters without a numeric constraint.');
    }

    public function test_belongs_to_workspace_scope_filters_rows(): void
    {
        [$actor, $own, $foreign] = $this->crossWorkspaceFixture();
        User::factory()->memberOf($foreign, WorkspaceRole::Viewer)->create();

        $this->assertSame([$actor->id], Membership::query()->forWorkspace($own)->pluck('user_id')->all());
        $this->assertSame(1, Membership::query()->forWorkspace($foreign->id)->count());
        $this->assertSame($own->id, Membership::query()->forWorkspace($own)->firstOrFail()->workspace()->sole()->id);
    }

    public function test_misspelt_ability_fails_loudly(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'workspace', 'ability:workspace:raed'])
            ->get('/api/v1/workspaces/{workspace}/probe', fn () => response()->json(['ok' => true]));
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Owner)->create();

        $this->withoutExceptionHandling();
        $this->expectException(ValueError::class);

        $this->actingAs($owner, 'sanctum')->getJson("/api/v1/workspaces/{$workspace->slug}/probe");
    }

    public function test_token_ability_requires_the_current_role(): void
    {
        // A route guarded by the ability alone, without a policy, isolates the middleware's role check.
        Route::middleware(['api', 'auth:sanctum', 'workspace', 'ability:audit:read'])
            ->get('/api/v1/workspaces/{workspace}/probe', fn () => response()->json(['ok' => true]));
        $workspace = Workspace::factory()->create();
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();
        $plain = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/workspaces/{$workspace->slug}/tokens", ['name' => 'ci', 'abilities' => ['audit:read']])
            ->assertCreated()->json('token');
        $this->assertIsString($plain);

        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson("/api/v1/workspaces/{$workspace->slug}/probe")->assertOk();

        Membership::where('user_id', $admin->id)->update(['role' => WorkspaceRole::Editor->value]);

        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson("/api/v1/workspaces/{$workspace->slug}/probe")
            ->assertForbidden()
            ->assertJsonPath('detail', 'Your current role no longer allows this API token ability.');
    }

    public function test_role_is_read_once_per_request(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Owner)->create();

        $membershipReads = 0;
        DB::listen(function (QueryExecuted $query) use (&$membershipReads): void {
            if (preg_match('/^select .* from [`"]?memberships[`"]?/i', $query->sql) === 1) {
                $membershipReads++;
            }
        });

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/workspaces/{$workspace->slug}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.role', 'owner');

        $this->assertSame(1, $membershipReads);
    }
}
