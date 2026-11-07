<?php

namespace Tests\Feature\Api;

use App\Enums\IncidentStatus;
use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsWorkspaceIsolation;
use Tests\TestCase;

class IncidentApiTest extends TestCase
{
    use AssertsWorkspaceIsolation;
    use RefreshDatabase;

    private Workspace $ws;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-06 10:00:00', 'UTC'));
        $this->ws = Workspace::factory()->create();
        $this->editor = User::factory()->memberOf($this->ws, WorkspaceRole::Editor)->create();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/workspaces/{$this->ws->slug}/incidents{$path}";
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function body(array $extra = []): array
    {
        return $extra + ['title' => 'API degraded', 'impact' => 'minor', 'status' => 'investigating', 'message' => 'We are looking into it.'];
    }

    private function openIncident(string $status = 'investigating'): int
    {
        return $this->int($this->actingAs($this->editor)->postJson($this->url(), $this->body(['status' => $status]))->assertCreated()->json('data.id'));
    }

    private function addUpdate(int $id, string $status, string $message = 'Update'): void
    {
        $this->actingAs($this->editor)->postJson($this->url("/{$id}/updates"), ['status' => $status, 'message' => $message])->assertCreated();
    }

    private function int(mixed $value): int
    {
        $this->assertIsInt($value);

        return $value;
    }

    private function tick(int $seconds = 60): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds($seconds));
    }

    public function test_lifecycle_investigating_to_resolved_and_reopen(): void
    {
        $monitor = Monitor::factory()->for($this->ws)->create();
        $page = StatusPage::factory()->create(['workspace_id' => $this->ws->id]);

        $response = $this->actingAs($this->editor)->postJson($this->url(), $this->body(['monitor_ids' => [$monitor->id], 'status_page_ids' => [$page->id]]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'investigating')
            ->assertJsonPath('data.resolved_at', null)
            ->assertJsonPath('data.started_at', '2026-11-06T10:00:00+00:00')
            ->assertJsonPath('data.monitor_ids', [$monitor->id])
            ->assertJsonPath('data.status_page_ids', [$page->id])
            ->assertJsonCount(1, 'data.updates')
            ->assertJsonStructure(['data' => ['id', 'title', 'impact', 'status', 'started_at', 'resolved_at', 'monitor_ids', 'status_page_ids', 'updates' => [['id', 'status', 'message', 'created_at']], 'created_at', 'updated_at']]);
        $id = $this->int($response->json('data.id'));

        $this->tick();
        $this->addUpdate($id, 'identified', 'Found the cause.');
        $this->tick();
        $this->addUpdate($id, 'resolved', 'Fixed.');

        $this->actingAs($this->editor)->getJson($this->url("/{$id}"))->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.resolved_at', '2026-11-06T10:02:00+00:00')
            ->assertJsonPath('data.updates.0.message', 'Fixed.')
            ->assertJsonPath('data.updates.2.status', 'investigating');

        $this->tick();
        $this->addUpdate($id, 'monitoring', 'It is back.');
        $this->actingAs($this->editor)->getJson($this->url("/{$id}"))
            ->assertJsonPath('data.status', 'monitoring')
            ->assertJsonPath('data.resolved_at', null);

        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['title' => 'API outage', 'impact' => 'major', 'monitor_ids' => []])
            ->assertOk()->assertJsonPath('data.title', 'API outage')->assertJsonPath('data.impact', 'major')->assertJsonPath('data.monitor_ids', []);
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['status' => 'resolved'])->assertOk()->assertJsonPath('data.status', 'monitoring');

        $this->actingAs($this->editor)->deleteJson($this->url("/{$id}"))->assertNoContent();
        $this->assertDatabaseMissing('incidents', ['id' => $id]);
        $this->assertDatabaseMissing('incident_updates', ['incident_id' => $id]);
        $this->assertDatabaseMissing('status_page_links', ['linkable_type' => 'incident', 'linkable_id' => $id]);

        $actions = AuditEvent::query()->where('action', 'like', 'incident.%')->orderBy('id')->pluck('action')->all();
        $this->assertSame(['incident.created', 'incident.update_added', 'incident.update_added', 'incident.update_added', 'incident.updated', 'incident.deleted'], $actions);
    }

    public function test_retrospective_incident_and_list_filters(): void
    {
        $past = $this->actingAs($this->editor)->postJson($this->url(), $this->body(['status' => 'resolved', 'started_at' => '2026-11-01T08:00:00Z']))
            ->assertCreated()->assertJsonPath('data.resolved_at', '2026-11-06T10:00:00+00:00')->json('data.id');
        $this->tick();
        $open = $this->openIncident();
        $this->tick();
        $newest = $this->actingAs($this->editor)->postJson($this->url(), $this->body(['started_at' => '2026-11-07T00:00:00Z']))->assertUnprocessable();

        $ids = fn (string $q): array => array_map($this->int(...), (array) $this->actingAs($this->editor)->getJson($this->url($q))->assertOk()->json('data.*.id'));
        $this->assertSame([$open, $past], $ids(''));
        $this->assertSame([$open], $ids('?state=open'));
        $this->assertSame([$past], $ids('?state=resolved'));
        $this->actingAs($this->editor)->getJson($this->url('?state=bogus'))->assertUnprocessable();

        $page = $this->actingAs($this->editor)->getJson($this->url('?limit=1'))->assertJsonCount(1, 'data')->json('meta.next_cursor');
        $this->assertIsString($page);
        $this->actingAs($this->editor)->getJson($this->url('?limit=1&cursor='.$page))->assertJsonPath('data.0.id', $past);
        $newest->assertJsonValidationErrors(['started_at']);
    }

    public function test_deleting_last_update_is_rejected(): void
    {
        $id = $this->openIncident();
        $only = $this->int(IncidentUpdate::query()->where('incident_id', $id)->value('id'));

        $this->actingAs($this->editor)->deleteJson($this->url("/{$id}/updates/{$only}"))
            ->assertUnprocessable()->assertHeader('Content-Type', 'application/problem+json');
        $this->assertDatabaseHas('incident_updates', ['id' => $only]);
    }

    public function test_deleting_an_update_recomputes_status_and_resolution(): void
    {
        $id = $this->openIncident();
        $this->tick();
        $this->addUpdate($id, 'resolved', 'Done.');
        $this->tick();
        $this->addUpdate($id, 'monitoring', 'Reopened.');
        $updates = IncidentUpdate::query()->where('incident_id', $id)->orderBy('id')->pluck('id')->map(fn (mixed $v): int => $this->int($v))->all();

        // Removing the reopening makes the resolution the latest again; it keeps that entry's own time.
        $this->actingAs($this->editor)->deleteJson($this->url("/{$id}/updates/{$updates[2]}"))->assertNoContent();
        $incident = Incident::query()->findOrFail($id);
        $this->assertSame(IncidentStatus::Resolved, $incident->status);
        $this->assertSame('2026-11-06T10:01:00+00:00', $incident->resolved_at?->toIso8601String());

        // Removing the resolution reopens it.
        $this->actingAs($this->editor)->deleteJson($this->url("/{$id}/updates/{$updates[1]}"))->assertNoContent();
        $incident->refresh();
        $this->assertSame(IncidentStatus::Investigating, $incident->status);
        $this->assertNull($incident->resolved_at);
    }

    public function test_deleting_an_update_sets_resolution_from_the_remaining_latest(): void
    {
        $id = $this->openIncident();
        $this->tick();
        $this->addUpdate($id, 'resolved', 'Done.');
        $incident = Incident::query()->findOrFail($id);
        $incident->forceFill(['status' => IncidentStatus::Monitoring, 'resolved_at' => null])->save();
        $this->tick();
        $this->addUpdate($id, 'monitoring', 'Watching.');
        $last = $this->int(IncidentUpdate::query()->where('incident_id', $id)->max('id'));
        $incident->forceFill(['resolved_at' => null])->save();

        $this->actingAs($this->editor)->deleteJson($this->url("/{$id}/updates/{$last}"))->assertNoContent();
        $incident->refresh();
        $this->assertSame(IncidentStatus::Resolved, $incident->status);
        $this->assertSame('2026-11-06T10:01:00+00:00', $incident->resolved_at?->toIso8601String());
    }

    public function test_editing_an_update_changes_the_message_only(): void
    {
        $id = $this->openIncident();
        $update = $this->int(IncidentUpdate::query()->where('incident_id', $id)->value('id'));

        $this->actingAs($this->editor)->patchJson($this->url("/{$id}/updates/{$update}"), ['message' => 'Edited', 'status' => 'resolved'])
            ->assertOk()->assertJsonPath('data.message', 'Edited')->assertJsonPath('data.status', 'investigating');
        $this->assertSame('investigating', Incident::query()->findOrFail($id)->status->value);
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}/updates/{$update}"), [])->assertUnprocessable()->assertJsonValidationErrors(['message']);
    }

    public function test_markdown_is_stored_verbatim(): void
    {
        $markdown = "# Heading\n\n<script>alert(1)</script> **bold** [x](javascript:alert(1))\n\n- item & <b>tag</b>";
        $title = 'Title <img src=x onerror=alert(1)>';

        $id = $this->int($this->actingAs($this->editor)->postJson($this->url(), $this->body(['title' => $title, 'message' => $markdown]))
            ->assertCreated()->assertJsonPath('data.title', $title)->assertJsonPath('data.updates.0.message', $markdown)->json('data.id'));

        $this->assertSame($title, Incident::query()->findOrFail($id)->title);
        $this->assertSame($markdown, IncidentUpdate::query()->where('incident_id', $id)->value('message'));
    }

    public function test_validation_limits(): void
    {
        $this->actingAs($this->editor)->postJson($this->url(), [])->assertUnprocessable()->assertJsonValidationErrors(['title', 'impact', 'status', 'message']);
        $this->actingAs($this->editor)->postJson($this->url(), $this->body(['title' => str_repeat('a', 191)]))->assertUnprocessable()->assertJsonValidationErrors(['title']);
        $this->actingAs($this->editor)->postJson($this->url(), $this->body(['message' => str_repeat('a', 10001)]))->assertUnprocessable()->assertJsonValidationErrors(['message']);
        $this->actingAs($this->editor)->postJson($this->url(), $this->body(['impact' => 'huge']))->assertUnprocessable()->assertJsonValidationErrors(['impact']);
        $this->actingAs($this->editor)->postJson($this->url(), $this->body(['status' => 'open']))->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->actingAs($this->editor)->postJson($this->url(), $this->body(['monitor_ids' => range(1, 1001)]))->assertUnprocessable()->assertJsonValidationErrors(['monitor_ids']);
        $this->actingAs($this->editor)->postJson($this->url(), $this->body(['status_page_ids' => range(1, 101)]))->assertUnprocessable()->assertJsonValidationErrors(['status_page_ids']);

        $id = $this->openIncident();
        $this->actingAs($this->editor)->postJson($this->url("/{$id}/updates"), ['status' => 'investigating'])->assertUnprocessable()->assertJsonValidationErrors(['message']);
        $this->actingAs($this->editor)->postJson($this->url("/{$id}/updates"), ['message' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->assertSame(0, Incident::query()->where('id', '!=', $id)->count());
    }

    public function test_viewers_read_but_cannot_write(): void
    {
        $viewer = User::factory()->memberOf($this->ws, WorkspaceRole::Viewer)->create();
        $id = $this->openIncident();

        $this->actingAs($viewer)->getJson($this->url())->assertOk();
        $this->actingAs($viewer)->getJson($this->url("/{$id}"))->assertOk();
        $this->actingAs($viewer)->postJson($this->url(), $this->body())->assertForbidden();
        $this->actingAs($viewer)->postJson($this->url("/{$id}/updates"), ['status' => 'resolved', 'message' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->deleteJson($this->url("/{$id}"))->assertForbidden();
    }

    public function test_workspace_isolation(): void
    {
        [$actor, $own, $foreign] = $this->crossWorkspaceFixture();
        $foreignMonitor = Monitor::factory()->for($foreign)->create();
        $foreignPage = StatusPage::factory()->create(['workspace_id' => $foreign->id]);
        $base = "/api/v1/workspaces/{$own->slug}/incidents";

        $this->actingAs($actor)->postJson($base, $this->body(['monitor_ids' => [$foreignMonitor->id]]))->assertUnprocessable()->assertJsonValidationErrors(['monitor_ids']);
        $this->actingAs($actor)->postJson($base, $this->body(['status_page_ids' => [$foreignPage->id]]))->assertUnprocessable()->assertJsonValidationErrors(['status_page_ids']);
        $this->assertSame(0, Incident::query()->count());

        $incident = Incident::factory()->create(['workspace_id' => $foreign->id]);
        $update = IncidentUpdate::factory()->create(['incident_id' => $incident->id]);
        $url = "{$base}/{$incident->id}";
        $this->assertCrossWorkspaceNotFound($actor, 'GET', $url);
        $this->assertCrossWorkspaceNotFound($actor, 'PATCH', $url, ['title' => 'x']);
        $this->assertCrossWorkspaceNotFound($actor, 'DELETE', $url);
        $this->assertCrossWorkspaceNotFound($actor, 'POST', "{$url}/updates", ['status' => 'resolved', 'message' => 'x']);
        $this->assertCrossWorkspaceNotFound($actor, 'PATCH', "{$url}/updates/{$update->id}", ['message' => 'x']);
        $this->assertCrossWorkspaceNotFound($actor, 'DELETE', "{$url}/updates/{$update->id}");
        $this->assertDatabaseHas('incidents', ['id' => $incident->id]);

        // An update of another incident of the same workspace is not reachable through this one.
        $mine = Incident::factory()->create(['workspace_id' => $own->id]);
        IncidentUpdate::factory()->create(['incident_id' => $mine->id]);
        $this->assertCrossWorkspaceNotFound($actor, 'DELETE', "{$base}/{$mine->id}/updates/{$update->id}");
        $this->assertCrossWorkspaceNotFound($actor, 'PATCH', "{$base}/{$mine->id}/updates/{$update->id}", ['message' => 'x']);
        $this->assertDatabaseHas('incident_updates', ['id' => $update->id]);

        $this->actingAs($actor)->getJson($base)->assertOk()->assertJsonCount(1, 'data');
    }
}
