<?php

namespace Tests\Feature\Status;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Status\Pages\AffectedPages;
use App\Status\Pages\PageStamps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An internal incident never reaches a public surface, even when it names a monitor the page shows.
 */
class InternalIncidentTest extends TestCase
{
    use PublicFeedFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFixture();
    }

    public function test_a_public_incident_naming_a_displayed_monitor_is_on_every_surface(): void
    {
        $this->get('/status/acme')->assertOk()->assertSee('Slow <b>site</b>');
        $this->assertStringContainsString('Slow &lt;b&gt;site&lt;/b&gt;', (string) $this->get('/status/acme/feed.atom')->getContent());
        $this->assertStringContainsString('Slow &lt;b&gt;site&lt;/b&gt;', (string) $this->get('/status/acme/feed.rss')->getContent());
        $summary = $this->get('/status/acme/summary.json')->assertOk();
        $this->assertCount(1, (array) $summary->json('incidents'));
        $this->assertSame('degraded', $summary->json('components.0.status'));
        $this->assertSame([$this->page->id], AffectedPages::forIncident($this->incident->id));
    }

    public function test_an_internal_incident_naming_a_displayed_monitor_is_on_no_surface(): void
    {
        $this->incident->update(['internal' => true]);

        $page = (string) $this->get('/status/acme')->assertOk()->getContent();
        $this->assertStringNotContainsString('Slow', $page);
        $this->assertStringNotContainsString('Fix deployed', $page);
        $this->assertStringNotContainsString('incident-'.$this->incident->id, $page);

        foreach (['/status/acme/feed.atom', '/status/acme/feed.rss'] as $feed) {
            $body = (string) $this->get($feed)->assertOk()->getContent();
            $this->assertStringNotContainsString('Slow', $body, $feed);
            $this->assertStringNotContainsString('incident-'.$this->incident->id, $body, $feed);
        }

        // Neither listed nor colouring the component or the page.
        $this->get('/status/acme/summary.json')->assertOk()
            ->assertJsonPath('incidents', [])
            ->assertJsonPath('components.0.status', 'operational')
            ->assertJsonPath('page.status', 'operational');
        $badge = (string) $this->get('/status/acme/badge.svg')->assertOk()->getContent();
        $this->assertStringContainsString(': operational', $badge);
    }

    public function test_an_internal_incident_linked_to_the_page_is_hidden_too(): void
    {
        $this->incident->monitors()->detach();
        $this->incident->update(['internal' => true]);
        $this->incident->statusPages()->attach($this->page->id, ['linkable_type' => 'incident']);

        $this->get('/status/acme/summary.json')->assertOk()->assertJsonPath('incidents', []);
        $this->assertStringNotContainsString('Slow', (string) $this->get('/status/acme')->getContent());
    }

    public function test_writes_to_an_internal_incident_move_no_public_page_stamp(): void
    {
        $this->incident->update(['internal' => true]);
        $this->assertSame([], AffectedPages::forIncident($this->incident->id));

        $editor = User::factory()->memberOf($this->workspace, WorkspaceRole::Editor)->create();
        $stamps = app(PageStamps::class);
        $before = $stamps->pageVersion($this->page->id);
        $base = "/api/v1/workspaces/{$this->workspace->slug}/incidents/{$this->incident->id}";

        $this->actingAs($editor)->postJson("{$base}/updates", ['status' => 'identified', 'message' => 'Secret root cause'])->assertCreated();
        $this->actingAs($editor)->patchJson($base, ['title' => 'Still secret'])->assertOk();
        $this->assertSame($before, $stamps->pageVersion($this->page->id));

        // Making it public again changes what the page shows, so the page moves.
        $this->actingAs($editor)->patchJson($base, ['internal' => false])->assertOk();
        $this->get('/status/acme')->assertOk()->assertSee('Still secret');
    }

    public function test_switching_to_internal_removes_the_incident_from_a_cached_page(): void
    {
        $this->get('/status/acme')->assertOk()->assertSee('Slow <b>site</b>');

        $editor = User::factory()->memberOf($this->workspace, WorkspaceRole::Editor)->create();
        $this->actingAs($editor)->patchJson("/api/v1/workspaces/{$this->workspace->slug}/incidents/{$this->incident->id}", ['internal' => true])->assertOk();

        $this->assertStringNotContainsString('Slow', (string) $this->get('/status/acme')->assertOk()->getContent());
    }
}
