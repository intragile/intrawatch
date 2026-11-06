<?php

namespace Tests\Feature\Api;

use App\Actions\CreateWorkspace;
use App\Enums\WorkspaceRole;
use App\Exceptions\SetupAlreadyCompleted;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProblemDetailsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  TestResponse<Response>  $response
     */
    private function assertProblem(TestResponse $response, int $status, string $title): void
    {
        $response->assertStatus($status)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', $status)
            ->assertJsonPath('title', $title)
            ->assertJsonPath('type', 'about:blank');
    }

    public function test_validation_error_is_problem_json(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->postJson('/api/v1/workspaces', [])
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 422)
            ->assertJsonStructure(['type', 'title', 'status', 'errors' => ['name']]);
    }

    public function test_validation_exception_keeps_its_status(): void
    {
        Route::middleware('api')->get('api/v1/_probe/lockout', fn () => throw ValidationException::withMessages(['email' => ['Too many.']])->status(429));

        $this->assertProblem($this->getJson('/api/v1/_probe/lockout'), 429, 'Too Many Requests');
    }

    public function test_login_lockout_is_429_problem(): void
    {
        $user = User::factory()->create();
        $credentials = ['email' => $user->email, 'password' => 'wrong-password'];
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/login', $credentials);
        }
        $last = $this->postJson('/api/v1/auth/login', $credentials);

        $this->assertProblem($last, 429, 'Too Many Requests');
    }

    public function test_unauthenticated_is_401_problem(): void
    {
        $this->assertProblem($this->getJson('/api/v1/me'), 401, 'Unauthorized');
    }

    public function test_forbidden_is_403_problem(): void
    {
        $workspace = Workspace::factory()->create();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();

        $this->assertProblem(
            $this->actingAs($viewer, 'sanctum')->patchJson("/api/v1/workspaces/{$workspace->slug}", ['name' => 'X']),
            403,
            'Forbidden',
        );
    }

    public function test_not_found_does_not_leak_model(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/workspaces/nope/members');

        $this->assertProblem($response, 404, 'Not Found');
        $this->assertStringNotContainsString('Workspace', (string) $response->getContent());
        $this->assertStringNotContainsString('App\\', (string) $response->getContent());
    }

    public function test_unknown_route_is_404_problem(): void
    {
        $this->assertProblem($this->getJson('/api/v1/does-not-exist'), 404, 'Not Found');
    }

    public function test_method_not_allowed_is_405_problem(): void
    {
        $response = $this->deleteJson('/api/v1/ping');

        $this->assertProblem($response, 405, 'Method Not Allowed');
        $response->assertHeader('Allow');
    }

    public function test_token_mismatch_is_419_problem(): void
    {
        Route::middleware('api')->get('api/v1/_probe/csrf', fn () => throw new TokenMismatchException('CSRF token mismatch.'));

        $this->assertProblem($this->getJson('/api/v1/_probe/csrf'), 419, 'Page Expired');
    }

    public function test_locked_is_423_problem(): void
    {
        Route::middleware('api')->get('api/v1/_probe/locked', fn () => throw new HttpException(423));

        $this->assertProblem($this->getJson('/api/v1/_probe/locked'), 423, 'Locked');
    }

    public function test_throttle_is_429_problem_with_retry_after(): void
    {
        Route::middleware(['api', 'throttle:1,1'])->get('api/v1/_probe/throttled', fn () => ['ok' => true]);

        $this->getJson('/api/v1/_probe/throttled')->assertOk();
        $response = $this->getJson('/api/v1/_probe/throttled');

        $this->assertProblem($response, 429, 'Too Many Requests');
        $response->assertHeader('Retry-After');
    }

    public function test_setup_already_completed_is_409_domain_problem(): void
    {
        Route::middleware('api')->get('api/v1/_probe/setup', fn () => throw SetupAlreadyCompleted::completed());

        $this->getJson('/api/v1/_probe/setup')
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 409)
            ->assertJsonPath('title', 'Conflict')
            ->assertJsonPath('type', 'urn:problem:setup-already-completed')
            ->assertJsonPath('detail', 'Setup has already been completed.');
    }

    public function test_unique_constraint_violation_is_409_problem(): void
    {
        Route::middleware('api')->get('api/v1/_probe/unique', function (): never {
            /** @var Connection $connection */
            $connection = DB::connection();

            throw new UniqueConstraintViolationException(
                $connection->getName() ?? 'testing',
                'insert into "x" ("a") values (?)',
                ['secret-binding'],
                new RuntimeException('duplicate key secret detail'),
            );
        });

        $response = $this->getJson('/api/v1/_probe/unique');

        $this->assertProblem($response, 409, 'Conflict');
        $this->assertStringNotContainsString('secret', (string) $response->getContent());
    }

    public function test_server_error_does_not_leak_when_debug_is_off(): void
    {
        config(['app.debug' => false]);
        Route::middleware('api')->get('api/v1/_probe/boom', fn () => throw new RuntimeException('database password is hunter2'));

        $response = $this->getJson('/api/v1/_probe/boom');

        $this->assertProblem($response, 500, 'Internal Server Error');
        $content = (string) $response->getContent();
        $this->assertStringNotContainsString('hunter2', $content);
        $this->assertStringNotContainsString('trace', $content);
        $this->assertStringNotContainsString('RuntimeException', $content);
        $response->assertJsonMissingPath('detail');
    }

    public function test_authentication_exception_is_401_problem(): void
    {
        Route::middleware('api')->get('api/v1/_probe/auth', fn () => throw new AuthenticationException);

        $this->assertProblem($this->getJson('/api/v1/_probe/auth'), 401, 'Unauthorized');
    }

    public function test_already_a_member_is_a_validation_problem(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Owner)->create();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/workspaces/{$workspace->slug}/members", ['email' => $owner->email, 'role' => 'viewer']);

        $response->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('title', 'Unprocessable Content')
            ->assertJsonStructure(['type', 'title', 'status', 'detail', 'errors' => ['email']]);
    }

    public function test_slug_race_retries_with_next_suffix(): void
    {
        $owner = User::factory()->create();
        $injected = false;

        // Insert the competing row after the slug is chosen and before the workspace insert.
        DB::listen(function ($query) use (&$injected): void {
            if ($injected || ! str_contains($query->sql, 'workspaces') || ! str_starts_with(strtolower($query->sql), 'select')) {
                return;
            }
            $injected = true;
            DB::table('workspaces')->insert([
                'public_id' => (string) Str::ulid(),
                'name' => 'Rival',
                'slug' => 'acme',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $workspace = app(CreateWorkspace::class)->handle($owner, 'Acme');

        $this->assertTrue($injected);
        $this->assertSame('acme-2', $workspace->slug);
        $this->assertSame(2, Workspace::count());
    }
}
