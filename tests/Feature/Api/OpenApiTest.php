<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenApiTest extends TestCase
{
    // Scramble reads column types from the schema, so the spec is generated against a migrated database.
    use RefreshDatabase;

    public function test_openapi_spec_is_served_and_current(): void
    {
        $live = $this->getJson('/api/v1/openapi.json')->assertOk()->json();

        $this->assertIsArray($live);
        $this->assertSame(
            json_decode((string) file_get_contents(base_path('docs/openapi.json')), true),
            $live,
            'docs/openapi.json is stale; run: php artisan scramble:export --path=docs/openapi.json',
        );
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
}
