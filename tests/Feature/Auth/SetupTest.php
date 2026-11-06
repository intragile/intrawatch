<?php

namespace Tests\Feature\Auth;

use App\Actions\CompleteSetup;
use App\Enums\WorkspaceRole;
use App\Exceptions\SetupAlreadyCompleted;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $stateful = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/setup'];

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
        $this->postJson('/api/v1/setup', [...$this->payload(), 'password' => 'short'], $this->stateful)
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertSame(0, User::count());
    }

    public function test_setup_without_a_session_is_rejected_before_anything_is_created(): void
    {
        $this->postJson('/api/v1/setup', $this->payload())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'urn:problem:session-unavailable')
            ->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'SANCTUM_STATEFUL_DOMAINS'));

        $this->assertSame(0, User::count());
        $this->getJson('/api/v1/setup')->assertExactJson(['required' => true]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function lockStores(): array
    {
        // The container uses the database store; the array store is what the rest of the suite runs on.
        return ['array store' => ['array'], 'database store' => ['database']];
    }

    #[DataProvider('lockStores')]
    public function test_setup_is_single_use_under_concurrency(string $store): void
    {
        config(['cache.default' => $store]);
        $this->assertSame($store, Cache::getDefaultDriver());
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

    public function test_setup_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/setup', [], $this->stateful)->assertStatus(422);
        }

        $this->postJson('/api/v1/setup', [], $this->stateful)->assertStatus(429);
    }
}
