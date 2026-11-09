<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChangelogApiTest extends TestCase
{
    use RefreshDatabase;

    private function firstReleasedVersion(): string
    {
        $changelog = file_get_contents(base_path('CHANGELOG.md'));
        $this->assertIsString($changelog);
        $found = preg_match('/^## \[(\d+\.\d+\.\d+[^\]]*)\]/m', $changelog, $match);
        $this->assertSame(1, $found);
        $this->assertArrayHasKey(1, $match);

        return $match[1];
    }

    public function test_guest_gets_a_401_problem(): void
    {
        $this->getJson('/api/v1/changelog')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 401);
    }

    public function test_signed_in_user_reads_the_newest_release_first(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/changelog')
            ->assertOk()
            ->assertJsonPath('data.0.version', $this->firstReleasedVersion())
            ->assertJsonPath('data.0.unreleased', false)
            ->assertJsonStructure(['data' => [['groups' => [['name', 'items' => [['html', 'text']]]]]], 'meta', 'locale', 'fallback']);
    }

    public function test_latest_matches_the_first_release(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/changelog/latest')
            ->assertOk()
            ->assertJsonPath('data.version', $this->firstReleasedVersion());
    }

    public function test_unknown_version_is_a_404_problem(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/changelog/0.0.1')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_accept_language_selects_hungarian(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/changelog', ['Accept-Language' => 'hu'])
            ->assertOk()
            ->assertJsonPath('locale', 'hu');
    }

    public function test_an_api_token_with_any_ability_can_read_it(): void
    {
        $token = User::factory()->create()->createToken('ci', ['monitors:read'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/changelog')
            ->assertOk()
            ->assertJsonPath('data.0.version', $this->firstReleasedVersion());
    }
}
