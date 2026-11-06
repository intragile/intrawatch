<?php

namespace Tests\Concerns;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Tests\TestCase;

/**
 * Shared checks for workspace-scoped endpoints: a resource of another workspace must be indistinguishable
 * from a missing one (404 problem), whichever workspace slug the request names.
 *
 * @mixin TestCase
 */
trait AssertsWorkspaceIsolation
{
    /**
     * An owner of workspace A who is not a member of workspace B.
     *
     * @return array{0: User, 1: Workspace, 2: Workspace}
     */
    protected function crossWorkspaceFixture(): array
    {
        $own = Workspace::factory()->create();
        $foreign = Workspace::factory()->create();
        $actor = User::factory()->memberOf($own, WorkspaceRole::Owner)->create();

        return [$actor, $own, $foreign];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function assertCrossWorkspaceNotFound(User $actor, string $method, string $uri, array $data = []): void
    {
        $this->app['auth']->forgetGuards();

        $this->actingAs($actor, 'sanctum')
            ->json($method, $uri, $data)
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json');
    }
}
