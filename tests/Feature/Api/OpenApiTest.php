<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\OpenApiController;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenApiTest extends TestCase
{
    // Scramble reads column types from the schema, so the spec is generated against a migrated database.
    use RefreshDatabase;

    /**
     * @return array<mixed>
     */
    private function committed(): array
    {
        $document = json_decode((string) file_get_contents(base_path(OpenApiController::DOCUMENT)), true);
        $this->assertIsArray($document);

        return $document;
    }

    public function test_committed_spec_is_current(): void
    {
        $generated = app(Generator::class)(Scramble::getGeneratorConfig('default'));

        $this->assertSame(
            $this->committed(),
            json_decode((string) json_encode($generated), true),
            'docs/openapi.json is stale; run: php artisan scramble:export --path=docs/openapi.json',
        );
    }

    public function test_endpoint_serves_the_committed_spec_without_generating_it(): void
    {
        $this->mock(Generator::class)->shouldNotReceive('__invoke');

        $response = $this->get('/api/v1/openapi.json')->assertOk()->assertHeader('Content-Type', 'application/json');

        $this->assertSame((string) file_get_contents(base_path(OpenApiController::DOCUMENT)), $response->getContent());
        $this->assertSame($this->committed(), $response->json());
    }

    public function test_spec_documents_versioned_paths_only(): void
    {
        $paths = array_keys((array) $this->getJson('/api/v1/openapi.json')->json('paths'));

        $this->assertNotEmpty($paths);
        $this->assertContains('/workspaces', $paths);
    }

    public function test_spec_is_public_and_docs_ui_is_not_exposed(): void
    {
        $this->getJson('/api/v1/openapi.json')->assertOk();
        $this->get('/docs/api')->assertNotFound();
        $this->get('/docs/api.json')->assertNotFound();
    }

    public function test_spec_endpoint_is_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->get('/api/v1/openapi.json')->assertOk();
        }

        $this->getJson('/api/v1/openapi.json')->assertStatus(429)->assertHeader('Content-Type', 'application/problem+json');
    }
}
