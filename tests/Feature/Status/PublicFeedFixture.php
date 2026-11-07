<?php

namespace Tests\Feature\Status;

use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Models\CheckRollup;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Maintenance;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\StatusPageItem;
use App\Models\StatusPageSection;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * One published page "acme" with a monitor, an open incident with two updates and an upcoming maintenance.
 */
trait PublicFeedFixture
{
    private CarbonImmutable $now;

    private Workspace $workspace;

    private StatusPage $page;

    private Monitor $monitor;

    private Monitor $other;

    private Incident $incident;

    private Maintenance $maintenance;

    protected function setUpFixture(): void
    {
        config(['app.url' => 'https://watch.example.com']);
        $this->now = CarbonImmutable::parse('2026-11-07 12:30:00', 'UTC');
        $this->travelTo($this->now);

        $this->workspace = Workspace::factory()->create(['timezone' => 'UTC']);
        $this->page = StatusPage::factory()->create([
            'workspace_id' => $this->workspace->id,
            'slug' => 'acme',
            'title' => 'Acme <status> & co',
            'published' => true,
        ]);
        $section = StatusPageSection::factory()->create(['status_page_id' => $this->page->id, 'name' => 'Core']);
        $this->monitor = Monitor::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Website']);
        $this->other = Monitor::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Hidden']);
        DB::table('monitor_states')->where('monitor_id', $this->monitor->id)->update(['status' => MonitorStatus::Up->value]);
        StatusPageItem::factory()->create(['status_page_section_id' => $section->id, 'monitor_id' => $this->monitor->id, 'position' => 0]);

        $this->incident = Incident::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Slow <b>site</b>',
            'impact' => IncidentImpact::Minor,
            'status' => IncidentStatus::Monitoring,
            'started_at' => $this->now->subHours(3),
        ]);
        $this->incident->monitors()->attach($this->monitor->id);
        IncidentUpdate::factory()->create([
            'incident_id' => $this->incident->id,
            'status' => IncidentStatus::Investigating,
            'message' => 'Looking into it',
            'created_at' => $this->now->subHours(3),
        ]);
        IncidentUpdate::factory()->create([
            'incident_id' => $this->incident->id,
            'status' => IncidentStatus::Monitoring,
            'message' => 'Fix deployed <script>alert(1)</script> **bold**',
            'created_at' => $this->now->subHour(),
        ]);

        $this->maintenance = Maintenance::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Database upgrade',
            'starts_at' => $this->now->addDay(),
            'ends_at' => $this->now->addDay()->addHour(),
        ]);
        $this->maintenance->monitors()->attach($this->monitor->id);

        $today = $this->now->startOfDay();
        foreach ([0, 1, 2] as $ago) {
            DB::table('check_rollups')->insert([
                'monitor_id' => $this->monitor->id,
                'bucket' => CheckRollup::BUCKET_DAY,
                'bucket_start' => $today->subDays($ago)->getTimestamp(),
                'total' => 1000, 'up' => 990, 'down' => 10,
            ]);
        }
        DB::table('check_rollups')->insert([
            'monitor_id' => $this->monitor->id,
            'bucket' => CheckRollup::BUCKET_HOUR,
            'bucket_start' => intdiv($this->now->getTimestamp(), 3600) * 3600,
            'total' => 10, 'up' => 10, 'latency_sum' => 1500, 'latency_count' => 10,
        ]);
    }

    /**
     * Runs the callback with the default connection pointing at a server that refuses connections.
     */
    protected function withDeadDatabase(callable $callback): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.dead' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'none',
            'username' => 'none',
            'password' => 'none',
        ]]);
        DB::setDefaultConnection('dead');
        try {
            $callback();
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('dead');
        }
    }
}
