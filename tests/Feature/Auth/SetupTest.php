<?php

namespace Tests\Feature\Auth;

use App\Actions\CompleteSetup;
use App\Enums\WorkspaceRole;
use App\Exceptions\SetupAlreadyCompleted;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function payload(string $email = 'owner@example.com'): array
    {
        return [
            'name' => 'Ada Owner',
            'email' => $email,
            'password' => 'correct-horse-battery',
            'workspace_name' => 'Acme Ops',
        ];
    }

    public function test_setup_creates_first_owner_once(): void
    {
        $headers = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/setup'];

        $this->getJson('/api/v1/setup')->assertExactJson(['required' => true]);

        $this->postJson('/api/v1/setup', $this->payload(), $headers)
            ->assertCreated()
            ->assertJsonPath('data.email', 'owner@example.com')
            ->assertJsonPath('data.workspaces.0.role', 'owner');

        $this->getJson('/api/v1/setup')->assertExactJson(['required' => false]);
        $this->getJson('/api/v1/me', $headers)->assertOk()->assertJsonPath('data.email', 'owner@example.com');
        $this->postJson('/api/v1/setup', $this->payload('other@example.com'), $headers)->assertStatus(409);

        $this->assertSame(1, User::count());
        $workspace = Workspace::where('slug', 'acme-ops')->firstOrFail();
        $this->assertSame(WorkspaceRole::Owner, User::firstOrFail()->roleIn($workspace));
    }

    public function test_setup_validates_input(): void
    {
        $this->postJson('/api/v1/setup', [...$this->payload(), 'password' => 'short'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertSame(0, User::count());
    }

    public function test_setup_is_single_use_under_concurrency(): void
    {
        $action = app(CompleteSetup::class);

        // Another process holds the lock: the call must fail without creating anything.
        $other = Cache::lock('setup', 10);
        $this->assertTrue($other->get());
        try {
            $action->handle($this->payload());
            $this->fail('Expected the setup to be rejected while the lock is held.');
        } catch (SetupAlreadyCompleted) {
            $this->assertSame(0, User::count());
        } finally {
            $other->release();
        }

        $action->handle($this->payload());
        $this->assertSame(1, User::count());

        $this->expectException(SetupAlreadyCompleted::class);
        try {
            $action->handle($this->payload('second@example.com'));
        } finally {
            $this->assertSame(1, User::count());
        }
    }
}
