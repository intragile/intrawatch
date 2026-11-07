<?php

namespace Tests\Feature\Status;

use App\Enums\IncidentStatus;
use App\Enums\TokenAbility;
use App\Enums\WorkspaceRole;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Maintenance;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\StatusPageItem;
use App\Models\StatusPageSection;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatusModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_monitor_cleans_pivots_and_items(): void
    {
        $ws = Workspace::factory()->create();
        $monitor = Monitor::factory()->create(['workspace_id' => $ws->id]);
        $maintenance = Maintenance::factory()->create(['workspace_id' => $ws->id]);
        $incident = Incident::factory()->create(['workspace_id' => $ws->id]);
        $page = StatusPage::factory()->create(['workspace_id' => $ws->id]);
        $section = StatusPageSection::factory()->create(['status_page_id' => $page->id]);
        StatusPageItem::factory()->create(['status_page_section_id' => $section->id, 'monitor_id' => $monitor->id]);
        $maintenance->monitors()->attach($monitor);
        $incident->monitors()->attach($monitor);

        $monitor->delete();

        $this->assertSame(0, DB::table('maintenance_monitor')->count());
        $this->assertSame(0, DB::table('incident_monitor')->count());
        $this->assertSame(0, DB::table('status_page_items')->count());
        $this->assertNotNull(Maintenance::find($maintenance->id));
        $this->assertNotNull(Incident::find($incident->id));
        $this->assertNotNull(StatusPage::find($page->id));
        $this->assertNotNull(StatusPageSection::find($section->id));
    }

    public function test_deleting_workspace_cascades_everything(): void
    {
        $ws = Workspace::factory()->create();
        $monitor = Monitor::factory()->create(['workspace_id' => $ws->id]);
        $maintenance = Maintenance::factory()->create(['workspace_id' => $ws->id]);
        $maintenance->monitors()->attach($monitor);
        $incident = Incident::factory()->create(['workspace_id' => $ws->id]);
        IncidentUpdate::factory()->create(['incident_id' => $incident->id]);
        $incident->monitors()->attach($monitor);
        $page = StatusPage::factory()->create(['workspace_id' => $ws->id]);
        $section = StatusPageSection::factory()->create(['status_page_id' => $page->id]);
        StatusPageItem::factory()->create(['status_page_section_id' => $section->id, 'monitor_id' => $monitor->id]);
        DB::table('status_page_links')->insert(['status_page_id' => $page->id, 'linkable_type' => 'incident', 'linkable_id' => $incident->id]);

        $ws->delete();

        foreach (['maintenances', 'maintenance_monitor', 'incidents', 'incident_updates', 'incident_monitor', 'status_pages', 'status_page_sections', 'status_page_items', 'status_page_links'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_deleting_incident_removes_its_page_links(): void
    {
        $ws = Workspace::factory()->create();
        $page = StatusPage::factory()->create(['workspace_id' => $ws->id]);
        $incident = Incident::factory()->create(['workspace_id' => $ws->id]);
        $other = Incident::factory()->create(['workspace_id' => $ws->id]);
        $maintenance = Maintenance::factory()->create(['workspace_id' => $ws->id]);
        foreach ([['incident', $incident->id], ['incident', $other->id], ['maintenance', $maintenance->id]] as [$type, $id]) {
            DB::table('status_page_links')->insert(['status_page_id' => $page->id, 'linkable_type' => $type, 'linkable_id' => $id]);
        }

        $incident->delete();
        $this->assertSame(2, DB::table('status_page_links')->count());
        $this->assertSame(0, DB::table('status_page_links')->where('linkable_type', 'incident')->where('linkable_id', $incident->id)->count());

        $maintenance->delete();
        $this->assertSame(1, DB::table('status_page_links')->count());
    }

    public function test_new_abilities_respect_minimum_role(): void
    {
        $this->assertSame(WorkspaceRole::Viewer, TokenAbility::IncidentsRead->minimumRole());
        $this->assertSame(WorkspaceRole::Editor, TokenAbility::IncidentsWrite->minimumRole());
        $this->assertSame(WorkspaceRole::Viewer, TokenAbility::StatusPagesRead->minimumRole());
        $this->assertSame(WorkspaceRole::Editor, TokenAbility::StatusPagesWrite->minimumRole());

        $ws = Workspace::factory()->create();
        $viewer = User::factory()->memberOf($ws, WorkspaceRole::Viewer)->create();
        $editor = User::factory()->memberOf($ws, WorkspaceRole::Editor)->create();
        $url = "/api/v1/workspaces/{$ws->slug}/tokens";

        foreach (['incidents:write', 'status-pages:write'] as $ability) {
            $this->actingAs($viewer, 'sanctum')->postJson($url, ['name' => 't', 'abilities' => [$ability]])->assertStatus(422);
            $this->actingAs($editor, 'sanctum')->postJson($url, ['name' => 't', 'abilities' => [$ability]])->assertCreated();
        }
        foreach (['incidents:read', 'status-pages:read'] as $ability) {
            $this->actingAs($viewer, 'sanctum')->postJson($url, ['name' => 't', 'abilities' => [$ability]])->assertCreated();
        }
    }

    public function test_valid_dates_are_not_shifted_by_timezone(): void
    {
        config(['app.timezone' => 'UTC']);
        $ws = Workspace::factory()->create(['timezone' => 'Pacific/Kiritimati']);
        $maintenance = Maintenance::factory()->create([
            'workspace_id' => $ws->id,
            'valid_from' => '2027-03-28',
            'valid_until' => '2027-10-31',
        ]);

        $fresh = Maintenance::findOrFail($maintenance->id);
        $this->assertSame('2027-03-28', $fresh->valid_from);
        $this->assertSame('2027-10-31', $fresh->valid_until);
        $this->assertSame('2027-03-28', $fresh->attributesToArray()['valid_from'] ?? null);
    }

    public function test_relations_and_ordering(): void
    {
        $ws = Workspace::factory()->create();
        $incident = Incident::factory()->create(['workspace_id' => $ws->id]);
        $second = IncidentUpdate::factory()->create(['incident_id' => $incident->id, 'status' => IncidentStatus::Identified, 'created_at' => '2027-01-01 10:00:00']);
        $first = IncidentUpdate::factory()->create(['incident_id' => $incident->id, 'status' => IncidentStatus::Investigating, 'created_at' => '2027-01-01 09:00:00']);
        $this->assertSame([$first->id, $second->id], $incident->updates->pluck('id')->all());
        $this->assertSame(IncidentStatus::Identified, $second->fresh()?->status);

        $page = StatusPage::factory()->create(['workspace_id' => $ws->id]);
        $b = StatusPageSection::factory()->create(['status_page_id' => $page->id, 'position' => 2]);
        $a = StatusPageSection::factory()->create(['status_page_id' => $page->id, 'position' => 1]);
        $monitor = Monitor::factory()->create(['workspace_id' => $ws->id]);
        $item = StatusPageItem::factory()->create(['status_page_section_id' => $b->id, 'monitor_id' => $monitor->id]);
        $this->assertSame([$a->id, $b->id], $page->sections->pluck('id')->all());
        $this->assertSame([$item->id], $page->items->pluck('id')->all());

        $this->assertSame([$incident->id], $ws->incidents->pluck('id')->all());
        $this->assertCount(1, $ws->statusPages);
        $this->assertCount(0, $ws->maintenances);
        $incident->monitors()->attach($monitor);
        $this->assertSame([$incident->id], $monitor->incidents->pluck('id')->all());
        $this->assertFalse($page->published);
        $this->assertSame('en', StatusPage::findOrFail($page->id)->locale);
    }
}
