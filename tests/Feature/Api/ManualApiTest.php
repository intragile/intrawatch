<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_gets_a_401_problem(): void
    {
        foreach (['/api/v1/manual', '/api/v1/manual/chapter?slug=start/getting-started', '/api/v1/manual/search?q=monitor'] as $uri) {
            $this->getJson($uri)
                ->assertUnauthorized()
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 401);
        }
    }

    public function test_index_lists_four_sections_and_eleven_chapters(): void
    {
        $response = $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/manual', ['Accept-Language' => 'hu'])
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.id', 'start')
            ->assertJsonPath('data.0.chapters.0.slug', 'start/getting-started')
            ->assertJsonPath('data.0.chapters.0.number', '1.1')
            ->assertJsonPath('locale', 'hu')
            ->assertJsonPath('fallback', false)
            ->assertJsonStructure(['data' => [['id', 'icon', 'title', 'chapters' => [['slug', 'number', 'title', 'summary']]]], 'locale', 'fallback']);

        /** @var list<array{chapters: list<mixed>}> $sections */
        $sections = $response->json('data');
        $this->assertSame(11, array_sum(array_map(fn (array $section): int => count($section['chapters']), $sections)));
    }

    public function test_chapter_is_served_as_html(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/manual/chapter?slug=start/getting-started', ['Accept-Language' => 'hu'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'start/getting-started')
            ->assertJsonPath('data.number', '1.1')
            ->assertJsonPath('data.title', 'Első lépések')
            ->assertJsonPath('data.prev', null)
            ->assertJsonPath('data.next.slug', 'monitoring/monitors')
            ->assertJsonPath('data.locale', 'hu')
            ->assertJsonPath('data.fallback', false)
            ->assertJsonStructure(['data' => ['html', 'headings' => [['anchor', 'text', 'level']], 'readingMinutes']]);
    }

    public function test_unknown_chapter_is_a_404_problem(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/manual/chapter?slug=start/nope')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_missing_slug_is_a_422_problem(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/manual/chapter')
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_search_finds_chapters(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/manual/search?q=státuszoldal', ['Accept-Language' => 'hu'])
            ->assertOk()
            ->assertJsonPath('locale', 'hu')
            ->assertJsonPath('fallback', false)
            ->assertJsonPath('data.0.slug', 'monitoring/status-pages')
            ->assertJsonStructure(['data' => [['slug', 'section', 'title', 'snippet', 'score']], 'locale', 'fallback']);
    }

    public function test_accept_language_selects_the_english_translation(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/manual/chapter?slug=start/getting-started', ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Getting started')
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.fallback', false);
    }

    public function test_an_unshipped_language_is_served_in_the_source_language(): void
    {
        $user = User::factory()->create();

        // The package resolves a language it does not ship to source_locale; `fallback` (en) only fills
        // chapters missing from a shipped language.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/manual/chapter?slug=start/getting-started', ['Accept-Language' => 'de'])
            ->assertOk()
            ->assertJsonPath('data.locale', 'hu')
            ->assertJsonPath('data.fallback', true);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/manual', ['Accept-Language' => 'de'])
            ->assertOk()
            ->assertJsonPath('locale', 'hu')
            ->assertJsonPath('fallback', true);
    }

    public function test_an_api_token_with_any_ability_can_read_it(): void
    {
        $token = User::factory()->create()->createToken('ci', ['monitors:read'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/manual')
            ->assertOk()
            ->assertJsonCount(4, 'data');
    }
}
