<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\OpenApiController;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * The JSON schema at a path of the committed spec, with a top-level `$ref` resolved.
     *
     * @param  list<string>  $path
     * @return array<string, mixed>
     */
    private function schemaAt(array $path): array
    {
        $node = $this->committed();
        foreach ($path as $key) {
            $this->assertIsArray($node);
            $this->assertArrayHasKey($key, $node, 'Missing in spec: '.implode(' > ', $path));
            $node = $node[$key];
        }
        $this->assertIsArray($node);
        if (isset($node['$ref']) && is_string($node['$ref'])) {
            return $this->schemaAt(array_slice(explode('/', $node['$ref']), 1));
        }

        /** @var array<string, mixed> $node */
        return $node;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    public static function writeEndpoints(): array
    {
        return [
            'monitor create' => ['/workspaces/{workspace}/monitors', 'post', ['type', 'name', 'interval_seconds', 'alerting', 'tags', 'notification_channel_ids', 'config', 'secrets', 'parent_id']],
            'monitor update' => ['/workspaces/{workspace}/monitors/{monitor}', 'patch', ['name', 'alerting', 'config', 'secrets']],
            'channel create' => ['/workspaces/{workspace}/notification-channels', 'post', ['type', 'name', 'templates', 'config', 'secrets']],
            'channel draft test' => ['/workspaces/{workspace}/notification-channels/test', 'post', ['type', 'config', 'secrets']],
            'channel update' => ['/workspaces/{workspace}/notification-channels/{channel}', 'patch', ['name', 'config', 'secrets']],
            'incident create' => ['/workspaces/{workspace}/incidents', 'post', ['title', 'impact', 'status', 'message']],
            'incident update' => ['/workspaces/{workspace}/incidents/{incident}', 'patch', ['title', 'impact']],
            'incident timeline entry' => ['/workspaces/{workspace}/incidents/{incident}/updates', 'post', ['status', 'message']],
            'maintenance create' => ['/workspaces/{workspace}/maintenances', 'post', ['title', 'strategy', 'monitor_ids', 'cron', 'weekdays']],
            'maintenance update' => ['/workspaces/{workspace}/maintenances/{maintenance}', 'patch', ['title', 'strategy']],
            'status page create' => ['/workspaces/{workspace}/status-pages', 'post', ['slug', 'title', 'custom_domain']],
            'status page update' => ['/workspaces/{workspace}/status-pages/{statusPage}', 'patch', ['slug', 'custom_domain']],
            'status page layout' => ['/workspaces/{workspace}/status-pages/{statusPage}/layout', 'put', ['sections']],
        ];
    }

    /**
     * @param  list<string>  $fields
     */
    #[DataProvider('writeEndpoints')]
    public function test_write_endpoints_document_their_request_body(string $path, string $method, array $fields): void
    {
        $body = $this->schemaAt(['paths', $path, $method, 'requestBody', 'content', 'application/json', 'schema']);

        $this->assertIsArray($body['properties'] ?? null);
        $this->assertNotEmpty($body['properties']);
        foreach ($fields as $field) {
            $this->assertArrayHasKey($field, $body['properties'], "{$method} {$path} does not document `{$field}`");
        }
        if ($method === 'patch') {
            $this->assertSame([], $body['required'] ?? [], "{$method} {$path} must not require any field");
        }
    }

    public function test_type_catalogues_and_monitor_tags_are_typed(): void
    {
        $monitorType = $this->schemaAt(['paths', '/monitor-types', 'get', 'responses', '200', 'content', 'application/json', 'schema', 'items']);
        $this->assertSame(['type', 'default_interval_seconds', 'min_interval_seconds', 'supports_inverted', 'scheduled', 'defaults'], array_keys((array) $monitorType['properties']));

        $channelType = $this->schemaAt(['paths', '/notification-channel-types', 'get', 'responses', '200', 'content', 'application/json', 'schema', 'items']);
        $this->assertSame(['type', 'supports_digest', 'defaults', 'config_keys', 'secret_keys'], array_keys((array) $channelType['properties']));

        $tag = $this->schemaAt(['paths', '/workspaces/{workspace}/monitors/{monitor}', 'get', 'responses', '200', 'content', 'application/json', 'schema', 'properties', 'data', 'properties', 'tags', 'items']);
        $this->assertSame(['id', 'name', 'color', 'value'], array_keys((array) $tag['properties']));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: list<string>}>
     */
    public static function nestedLists(): array
    {
        $section = ['id', 'name', 'description', 'position', 'items'];
        $update = ['id', 'status', 'message', 'created_at', 'updated_at'];

        return [
            'status page index' => ['/workspaces/{workspace}/status-pages', 'get', 'sections', $section],
            'status page create' => ['/workspaces/{workspace}/status-pages', 'post', 'sections', $section],
            'status page show' => ['/workspaces/{workspace}/status-pages/{statusPage}', 'get', 'sections', $section],
            'status page update' => ['/workspaces/{workspace}/status-pages/{statusPage}', 'patch', 'sections', $section],
            'status page layout' => ['/workspaces/{workspace}/status-pages/{statusPage}/layout', 'put', 'sections', $section],
            'incident index' => ['/workspaces/{workspace}/incidents', 'get', 'updates', $update],
            'incident create' => ['/workspaces/{workspace}/incidents', 'post', 'updates', $update],
            'incident show' => ['/workspaces/{workspace}/incidents/{incident}', 'get', 'updates', $update],
            'incident update' => ['/workspaces/{workspace}/incidents/{incident}', 'patch', 'updates', $update],
        ];
    }

    /**
     * @param  list<string>  $keys
     */
    #[DataProvider('nestedLists')]
    public function test_nested_lists_are_typed_as_objects(string $path, string $method, string $field, array $keys): void
    {
        $status = $method === 'post' ? '201' : '200';
        $data = $this->schemaAt(['paths', $path, $method, 'responses', $status, 'content', 'application/json', 'schema', 'properties', 'data']);
        if (($data['type'] ?? null) === 'array') {
            $this->assertIsArray($data['items'] ?? null);
            /** @var array<string, mixed> $data */
            $data = $data['items'];
        }

        $this->assertIsArray($data['properties'] ?? null);
        $list = $data['properties'][$field] ?? null;
        $this->assertIsArray($list, "{$method} {$path} does not document `{$field}`");
        $this->assertSame('array', $list['type'] ?? null);
        $this->assertIsArray($list['items'] ?? null);
        $this->assertSame('object', $list['items']['type'] ?? null, "{$method} {$path} types `{$field}` items as scalars");
        $this->assertSame($keys, array_keys((array) $list['items']['properties']));
    }

    public function test_status_page_items_are_typed(): void
    {
        $section = $this->schemaAt(['paths', '/workspaces/{workspace}/status-pages/{statusPage}', 'get', 'responses', '200', 'content', 'application/json', 'schema', 'properties', 'data', 'properties', 'sections', 'items']);
        $this->assertIsArray($section['properties'] ?? null);
        $this->assertIsArray($section['properties']['items'] ?? null);
        $items = $section['properties']['items'];

        $this->assertSame('array', $items['type'] ?? null);
        $this->assertIsArray($items['items'] ?? null);
        $this->assertSame('object', $items['items']['type'] ?? null);
        $this->assertIsArray($items['items']['properties'] ?? null);
        $this->assertSame(['id', 'monitor_id', 'display_name', 'description', 'position', 'expand_children', 'show_uptime'], array_keys($items['items']['properties']));
        $this->assertSame(['type' => 'boolean'], $items['items']['properties']['expand_children']);
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
