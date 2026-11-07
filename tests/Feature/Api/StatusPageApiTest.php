<?php

namespace Tests\Feature\Api;

use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AssertsWorkspaceIsolation;
use Tests\TestCase;

class StatusPageApiTest extends TestCase
{
    use AssertsWorkspaceIsolation;
    use RefreshDatabase;

    private Workspace $ws;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store('status_pages')->flush();
        $this->ws = Workspace::factory()->create();
        $this->editor = User::factory()->memberOf($this->ws, WorkspaceRole::Editor)->create();
    }

    protected function tearDown(): void
    {
        Cache::store('status_pages')->flush();
        parent::tearDown();
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/workspaces/{$this->ws->slug}/status-pages{$path}";
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function body(array $extra = []): array
    {
        return $extra + ['slug' => 'acme', 'title' => 'Acme status'];
    }

    private function createPage(string $slug = 'acme'): int
    {
        $id = $this->actingAs($this->editor)->postJson($this->url(), $this->body(['slug' => $slug]))->assertCreated()->json('data.id');
        $this->assertIsInt($id);

        return $id;
    }

    public function test_crud_layout_replace_and_audit(): void
    {
        $a = Monitor::factory()->for($this->ws)->create();
        $b = Monitor::factory()->for($this->ws)->create();
        $id = $this->createPage();

        $this->actingAs($this->editor)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.0.slug', 'acme')->assertJsonMissingPath('data.0.sections');

        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['title' => 'Renamed', 'published' => true, 'bar_days' => 30, 'accent_color' => '#AABBCC'])
            ->assertOk()->assertJsonPath('data.title', 'Renamed')->assertJsonPath('data.published', true)->assertJsonPath('data.accent_color', '#aabbcc');

        $layout = ['sections' => [
            ['name' => 'Core', 'description' => 'Main', 'items' => [
                ['monitor_id' => $a->id, 'display_name' => 'API'],
                ['monitor_id' => $b->id, 'expand_children' => true, 'show_uptime' => false],
            ]],
            ['name' => 'Empty'],
        ]];
        $this->actingAs($this->editor)->putJson($this->url("/{$id}/layout"), $layout)->assertOk()
            ->assertJsonPath('data.sections.0.name', 'Core')
            ->assertJsonPath('data.sections.0.items.0.monitor_id', $a->id)
            ->assertJsonPath('data.sections.0.items.0.display_name', 'API')
            ->assertJsonPath('data.sections.0.items.1.expand_children', true)
            ->assertJsonPath('data.sections.0.items.1.show_uptime', false)
            ->assertJsonPath('data.sections.0.items.1.position', 1)
            ->assertJsonPath('data.sections.1.position', 1)
            ->assertJsonPath('data.sections.1.items', []);

        $this->actingAs($this->editor)->putJson($this->url("/{$id}/layout"), ['sections' => [['name' => 'Only', 'items' => [['monitor_id' => $b->id]]]]])->assertOk()
            ->assertJsonCount(1, 'data.sections');
        $this->assertSame(1, DB::table('status_page_sections')->count());
        $this->assertSame(1, DB::table('status_page_items')->count());

        $this->actingAs($this->editor)->getJson($this->url("/{$id}"))->assertOk()->assertJsonPath('data.sections.0.items.0.monitor_id', $b->id);

        $this->actingAs($this->editor)->deleteJson($this->url("/{$id}"))->assertNoContent();
        $this->assertSame(0, DB::table('status_page_items')->count());
        $this->actingAs($this->editor)->getJson($this->url("/{$id}"))->assertNotFound();

        $actions = AuditEvent::query()->orderBy('id')->pluck('action')->all();
        $this->assertSame(['status_page.created', 'status_page.updated', 'status_page.layout_replaced', 'status_page.layout_replaced', 'status_page.deleted'], $actions);
        $replaced = AuditEvent::query()->where('action', 'status_page.layout_replaced')->orderBy('id')->firstOrFail();
        $this->assertEquals(['sections' => [0, 2], 'items' => [0, 2]], $replaced->changes);
    }

    public function test_layout_rejects_foreign_or_duplicate_monitors_and_limits(): void
    {
        $own = Monitor::factory()->for($this->ws)->create();
        $foreign = Monitor::factory()->for(Workspace::factory()->create())->create();
        $id = $this->createPage();
        $put = fn (array $sections) => $this->actingAs($this->editor)->putJson($this->url("/{$id}/layout"), ['sections' => $sections]);

        $put([['name' => 'S', 'items' => [['monitor_id' => $foreign->id]]]])->assertUnprocessable()->assertHeader('Content-Type', 'application/problem+json');
        $put([['name' => 'S', 'items' => [['monitor_id' => $own->id]]], ['name' => 'T', 'items' => [['monitor_id' => $own->id]]]])->assertUnprocessable();
        $put([['name' => 'S', 'items' => [['monitor_id' => $own->id], ['monitor_id' => $own->id]]]])->assertUnprocessable();
        $put(array_fill(0, 51, ['name' => 'S']))->assertUnprocessable();
        $put([['name' => '']])->assertUnprocessable();
        $this->actingAs($this->editor)->putJson($this->url("/{$id}/layout"), [])->assertUnprocessable();

        $monitors = Monitor::factory()->for($this->ws)->count(251)->create();
        $items = $monitors->map(fn (Monitor $m): array => ['monitor_id' => $m->id])->all();
        $put([['name' => 'A', 'items' => array_slice($items, 0, 126)], ['name' => 'B', 'items' => array_slice($items, 126)]])->assertUnprocessable();
        $put([['name' => 'A', 'items' => array_slice($items, 0, 125)], ['name' => 'B', 'items' => array_slice($items, 126, 125)]])->assertOk();
        $this->assertSame(250, DB::table('status_page_items')->count());
    }

    public function test_slug_and_custom_domain_rules(): void
    {
        $id = $this->createPage();

        foreach (['api', 'admin', 'assets', 'badge', 'feed', 'status', 'setup', 'login'] as $reserved) {
            $this->actingAs($this->editor)->postJson($this->url(), $this->body(['slug' => $reserved]))->assertUnprocessable()->assertJsonValidationErrors('slug');
        }
        foreach (['ab', 'Upper', '-lead', 'trail-', 'sp ace', str_repeat('a', 65)] as $bad) {
            $this->actingAs($this->editor)->postJson($this->url(), $this->body(['slug' => $bad]))->assertUnprocessable()->assertJsonValidationErrors('slug');
        }
        $this->actingAs($this->editor)->postJson($this->url(), $this->body())->assertUnprocessable()->assertJsonValidationErrors('slug');

        $admin = User::factory()->memberOf($this->ws, WorkspaceRole::Admin)->create();
        $this->actingAs($admin)->patchJson($this->url("/{$id}"), ['custom_domain' => 'Status.Example.ORG.'])
            ->assertOk()->assertJsonPath('data.custom_domain', 'status.example.org');
        $this->assertSame('status.example.org', StatusPage::query()->findOrFail($id)->custom_domain);

        $other = $this->createPage('other');
        $this->actingAs($admin)->patchJson($this->url("/{$other}"), ['custom_domain' => 'STATUS.example.org'])->assertUnprocessable()->assertJsonValidationErrors('custom_domain');

        config(['app.url' => 'https://monitor.example.net']);
        foreach (['10.0.0.1', '2001:db8::1', 'localhost', 'monitor.example.net', 'Monitor.Example.Net.', 'bad_host.example.org', 'http://x.example.org'] as $bad) {
            $this->actingAs($admin)->patchJson($this->url("/{$other}"), ['custom_domain' => $bad])->assertUnprocessable()->assertJsonValidationErrors('custom_domain');
        }
        $this->actingAs($admin)->patchJson($this->url("/{$other}"), ['custom_domain' => null])->assertOk()->assertJsonPath('data.custom_domain', null);

        $this->actingAs($this->editor)->patchJson($this->url("/{$other}"), ['slug' => 'acme'])->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->actingAs($this->editor)->patchJson($this->url("/{$other}"), ['slug' => 'other'])->assertOk();
    }

    public function test_setting_validation(): void
    {
        $id = $this->createPage();
        $bad = [
            ['logo_url' => 'http://x.example.org/a.png'], ['logo_url' => 'https://user:pw@x.example.org/a.png'], ['favicon_url' => 'javascript:alert(1)'],
            ['accent_color' => 'red'], ['accent_color' => '#fff'], ['bar_days' => 6], ['bar_days' => 366], ['uptime_days' => 14],
            ['upcoming_maintenance_days' => 91], ['incident_history_days' => -1], ['locale' => 'de'], ['custom_css' => str_repeat('a', 20001)],
        ];
        foreach ($bad as $payload) {
            $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), $payload)->assertUnprocessable();
        }
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), [
            'logo_url' => 'https://cdn.example.org/logo.png', 'uptime_days' => 365, 'locale' => 'hu', 'custom_css' => str_repeat('a', 20000),
        ])->assertOk()->assertJsonPath('data.locale', 'hu');
    }

    public function test_abilities_gate_reads_and_writes(): void
    {
        $viewer = User::factory()->memberOf($this->ws, WorkspaceRole::Viewer)->create();
        $this->actingAs($viewer)->getJson($this->url())->assertOk();
        $this->actingAs($viewer)->postJson($this->url(), $this->body())->assertForbidden();
    }

    public function test_workspace_isolation(): void
    {
        [$actor, $own, $foreign] = $this->crossWorkspaceFixture();
        $page = StatusPage::factory()->for($foreign)->create();
        $base = "/api/v1/workspaces/{$foreign->slug}/status-pages";

        $this->assertCrossWorkspaceNotFound($actor, 'GET', $base);
        $this->assertCrossWorkspaceNotFound($actor, 'POST', $base, $this->body());
        $this->assertCrossWorkspaceNotFound($actor, 'GET', "{$base}/{$page->id}");
        $this->assertCrossWorkspaceNotFound($actor, 'PUT', "{$base}/{$page->id}/layout", ['sections' => []]);
        $this->assertCrossWorkspaceNotFound($actor, 'GET', "/api/v1/workspaces/{$own->slug}/status-pages/{$page->id}");
        $this->assertCrossWorkspaceNotFound($actor, 'PATCH', "/api/v1/workspaces/{$own->slug}/status-pages/{$page->id}", ['title' => 'x']);
        $this->assertCrossWorkspaceNotFound($actor, 'DELETE', "/api/v1/workspaces/{$own->slug}/status-pages/{$page->id}");
    }

    public function test_only_admins_set_or_change_the_custom_domain(): void
    {
        $admin = User::factory()->memberOf($this->ws, WorkspaceRole::Admin)->create();
        $id = $this->createPage();

        $this->actingAs($this->editor)->postJson($this->url(), $this->body(['slug' => 'second', 'custom_domain' => 'second.example.org']))
            ->assertForbidden()->assertHeader('Content-Type', 'application/problem+json');
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['custom_domain' => 'status.example.org'])->assertForbidden();
        $this->assertNull(StatusPage::query()->findOrFail($id)->custom_domain);

        $this->actingAs($admin)->patchJson($this->url("/{$id}"), ['custom_domain' => 'status.example.org'])->assertOk();

        // Editors keep editing everything else, and may resend the domain unchanged.
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['title' => 'New title', 'custom_domain' => 'Status.Example.org'])->assertOk();
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['custom_domain' => null])->assertForbidden();
        $this->actingAs($this->editor)->postJson($this->url(), $this->body(['slug' => 'third', 'custom_domain' => null]))->assertCreated();
        $this->assertSame('status.example.org', StatusPage::query()->findOrFail($id)->custom_domain);
    }

    public function test_the_custom_domain_cannot_be_the_host_the_api_is_reached_on(): void
    {
        $admin = User::factory()->memberOf($this->ws, WorkspaceRole::Admin)->create();
        $id = $this->createPage();
        config(['app.url' => 'https://monitor.example.net']);

        $this->actingAs($admin)->patchJson("http://admin.example.net{$this->url("/{$id}")}", ['custom_domain' => 'admin.example.net'])
            ->assertUnprocessable()->assertJsonValidationErrors('custom_domain');
        $this->actingAs($admin)->patchJson("http://ADMIN.example.net:8443{$this->url("/{$id}")}", ['custom_domain' => 'Admin.Example.Net.'])
            ->assertUnprocessable()->assertJsonValidationErrors('custom_domain');
        $this->actingAs($admin)->patchJson("http://admin.example.net{$this->url("/{$id}")}", ['custom_domain' => 'status.example.org'])->assertOk();
    }
}
