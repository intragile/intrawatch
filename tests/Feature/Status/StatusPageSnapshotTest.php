<?php

namespace Tests\Feature\Status;

use App\Enums\DisplayStatus;
use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use App\Enums\MaintenanceStrategy;
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
use App\Status\Pages\ItemView;
use App\Status\Pages\PageView;
use App\Status\Pages\StatusPageSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatusPageSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Budapest';

    private CarbonImmutable $now;

    private Workspace $workspace;

    private StatusPage $page;

    private StatusPageSection $section;

    private int $position = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = CarbonImmutable::parse('2026-11-07 12:30:20', 'UTC');
        CarbonImmutable::setTestNow($this->now);
        $this->workspace = Workspace::factory()->create(['timezone' => self::TZ]);
        $this->page = StatusPage::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Acme status',
            'description' => 'All **systems**',
            'locale' => 'hu',
        ]);
        $this->section = StatusPageSection::factory()->create(['status_page_id' => $this->page->id, 'name' => 'Core', 'position' => 0]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_partial_outage_for_group_with_some_children_down(): void
    {
        $partial = $this->monitor('API', MonitorStatus::Down, group: true);
        $this->monitor('b-api-2', MonitorStatus::Down, parent: $partial);
        $this->monitor('a-api-1', MonitorStatus::Up, parent: $partial);
        $this->monitor('c-api-3', MonitorStatus::Up, parent: $partial, active: false);

        $major = $this->monitor('Workers', MonitorStatus::Down, group: true);
        $this->monitor('worker-1', MonitorStatus::Down, parent: $major);
        $this->monitor('worker-2', MonitorStatus::Paused, parent: $major);
        $this->monitor('worker-3', MonitorStatus::Maintenance, parent: $major);

        $this->item($partial, expand: true, displayName: 'Public API', description: 'REST and GraphQL');
        $this->item($major);

        $view = $this->build();

        $this->assertSame('Acme status', $view->title);
        $this->assertSame(self::TZ, $view->timezone);
        $this->assertSame('hu', $view->locale);
        $this->assertStringContainsString('<strong>systems</strong>', (string) $view->descriptionHtml);
        $this->assertCount(1, $view->sections);
        [$api, $workers] = $view->sections[0]->items;

        $this->assertSame('Public API', $api->name);
        $this->assertSame('REST and GraphQL', $api->description);
        $this->assertSame(DisplayStatus::PartialOutage, $api->status);
        $this->assertSame(['a-api-1', 'b-api-2', 'c-api-3'], array_map(fn (ItemView $c): string => $c->name, $api->children));
        $this->assertSame(DisplayStatus::Operational, $api->children[0]->status);
        $this->assertSame(DisplayStatus::MajorOutage, $api->children[1]->status);
        $this->assertSame([], $api->children[0]->children);

        $this->assertSame('Workers', $workers->name);
        $this->assertSame(DisplayStatus::MajorOutage, $workers->status);
        $this->assertSame([], $workers->children);

        $this->assertSame(DisplayStatus::MajorOutage, $view->overall);
        $this->assertSame('major_outage', $view->overallLabelKey);
        $this->assertNull($view->expiresAt);
        $this->assertTrue($view->generatedAt->equalTo($this->now));
    }

    public function test_open_incident_raises_display_status(): void
    {
        $web = $this->monitor('Web', MonitorStatus::Up);
        $mail = $this->monitor('Mail', MonitorStatus::Up);
        $this->item($web);
        $this->item($mail);
        $other = $this->monitor('Not on page', MonitorStatus::Up);

        $open = $this->incident('Web is slow', IncidentImpact::Major, IncidentStatus::Identified, [$web, $other]);
        IncidentUpdate::factory()->create(['incident_id' => $open->id, 'status' => IncidentStatus::Investigating, 'message' => 'Looking <script>x</script>', 'created_at' => $this->now->subMinutes(30)]);
        IncidentUpdate::factory()->create(['incident_id' => $open->id, 'status' => IncidentStatus::Identified, 'message' => 'Found *it*', 'created_at' => $this->now->subMinutes(10)]);
        $resolved = $this->incident('Mail outage', IncidentImpact::Critical, IncidentStatus::Resolved, [$mail], resolvedAt: $this->now->subDays(2));
        $this->incident('Ancient', IncidentImpact::Critical, IncidentStatus::Resolved, [$mail], resolvedAt: $this->now->subDays(20));
        $this->incident('Elsewhere', IncidentImpact::Critical, IncidentStatus::Investigating, [$other]);
        $linked = $this->incident('Page-wide notice', IncidentImpact::None, IncidentStatus::Monitoring, []);
        DB::table('status_page_links')->insert(['status_page_id' => $this->page->id, 'linkable_type' => 'incident', 'linkable_id' => $linked->id]);
        $foreignPage = StatusPage::factory()->create(['workspace_id' => $this->workspace->id]);
        $foreign = $this->incident('Other page', IncidentImpact::Critical, IncidentStatus::Investigating, []);
        DB::table('status_page_links')->insert(['status_page_id' => $foreignPage->id, 'linkable_type' => 'incident', 'linkable_id' => $foreign->id]);

        $view = $this->build();

        [$webView, $mailView] = $view->sections[0]->items;
        $this->assertSame(DisplayStatus::PartialOutage, $webView->status);
        $this->assertSame(DisplayStatus::Operational, $mailView->status);
        $this->assertSame(DisplayStatus::PartialOutage, $view->overall);
        $this->assertSame('partial_outage', $view->overallLabelKey);

        $this->assertSame(['Page-wide notice', 'Web is slow'], array_map(fn ($i) => $i->title, $view->openIncidents));
        $incident = $view->openIncidents[1];
        $this->assertSame($open->id, $incident->id);
        $this->assertSame(IncidentImpact::Major, $incident->impact);
        $this->assertSame(IncidentStatus::Identified, $incident->status);
        $this->assertNull($incident->resolvedAt);
        $this->assertSame(['Web'], $incident->affectedComponents);
        $this->assertCount(2, $incident->updates);
        $this->assertSame(IncidentStatus::Identified, $incident->updates[0]->status);
        $this->assertStringContainsString('<em>it</em>', $incident->updates[0]->messageHtml);
        $this->assertStringNotContainsString('<script>', $incident->updates[1]->messageHtml);
        $this->assertSame([], $view->openIncidents[0]->affectedComponents);

        $this->assertCount(1, $view->pastIncidents);
        $this->assertSame($resolved->id, $view->pastIncidents[0]->id);
        $this->assertNotNull($view->pastIncidents[0]->resolvedAt);
        $this->assertSame(['Mail'], $view->pastIncidents[0]->affectedComponents);
    }

    public function test_day_bars_and_uptime_from_rollups(): void
    {
        $web = $this->monitor('Web', MonitorStatus::Up);
        $hidden = $this->monitor('Hidden uptime', MonitorStatus::Up);
        $this->item($web);
        $this->item($hidden, showUptime: false);

        $today = $this->now->setTimezone(self::TZ)->startOfDay();
        $this->dayRollup($web, $today, up: 1430, down: 10);
        $this->dayRollup($web, $today->subDay(), up: 1440);
        $this->dayRollup($web, $today->subDays(3), maintenance: 60, pending: 2);
        $this->dayRollup($web, $today->subDays(29), up: 1000, down: 440);
        $this->dayRollup($web, $today->subDays(30), down: 1440);
        $this->dayRollup($web, $today->subDays(95), down: 1440);
        $this->dayRollup($hidden, $today, up: 1440);

        $hour = intdiv($this->now->getTimestamp(), 3600) * 3600;
        $this->hourRollup($web, $hour, 1200, 10);
        $this->hourRollup($web, $hour - 3600, 0, 0);
        $this->hourRollup($web, $hour - 2 * 3600, 3001, 20);
        $this->hourRollup($web, $hour - 24 * 3600, 999, 1);

        $view = $this->build();
        [$item, $hiddenItem] = $view->sections[0]->items;

        $this->assertCount(90, $item->bars);
        $this->assertSame('2026-11-07', $item->bars[89]->date);
        $this->assertSame('2026-08-10', $item->bars[0]->date);
        $this->assertSame(DisplayStatus::PartialOutage, $item->bars[89]->status);
        $this->assertSame(99.31, $item->bars[89]->uptimePercent);
        $this->assertSame(10, $item->bars[89]->downMinutesApprox);
        $this->assertSame(DisplayStatus::Operational, $item->bars[88]->status);
        $this->assertSame(DisplayStatus::Unknown, $item->bars[87]->status);
        $this->assertNull($item->bars[87]->uptimePercent);
        $this->assertSame(DisplayStatus::Maintenance, $item->bars[86]->status);
        $this->assertSame(DisplayStatus::MajorOutage, $item->bars[60]->status);
        $this->assertSame(DisplayStatus::MajorOutage, $item->bars[59]->status);

        // 30 local days: today, yesterday and day -29 count; day -30 is outside.
        $expected = round((1430 + 1440 + 1000) / (1440 * 3) * 100, 2);
        $this->assertSame($expected, $item->uptimePercent);
        $this->assertNull($hiddenItem->uptimePercent);
        $this->assertCount(90, $hiddenItem->bars);

        $this->assertNotNull($item->latencyHourly);
        $this->assertCount(24, $item->latencyHourly);
        $this->assertSame(120, $item->latencyHourly[23]);
        $this->assertNull($item->latencyHourly[22]);
        $this->assertSame(150, $item->latencyHourly[21]);
        $this->assertNull($item->latencyHourly[0]);

        $this->page->update(['show_latency' => false, 'uptime_days' => 365, 'bar_days' => 7]);
        $view = $this->build();
        $item = $view->sections[0]->items[0];
        $this->assertNull($item->latencyHourly);
        $this->assertCount(7, $item->bars);
        $expected = round((1430 + 1440 + 1000) / (1440 * 5) * 100, 2);
        $this->assertSame($expected, $item->uptimePercent);
    }

    public function test_bars_follow_the_workspace_day_across_daylight_saving_time(): void
    {
        $this->now = CarbonImmutable::parse('2026-10-26 08:00:00', 'UTC');
        CarbonImmutable::setTestNow($this->now);
        $web = $this->monitor('Web', MonitorStatus::Up);
        $this->item($web);
        $this->page->update(['bar_days' => 7]);
        // 2026-10-25 is the 25-hour day of the fall-back transition in Budapest.
        $this->dayRollup($web, CarbonImmutable::parse('2026-10-25', self::TZ), up: 1500);
        $this->dayRollup($web, CarbonImmutable::parse('2026-10-24', self::TZ), down: 1440);

        $bars = $this->build()->sections[0]->items[0]->bars;

        $this->assertSame(['2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25', '2026-10-26'], array_map(fn ($b) => $b->date, $bars));
        $this->assertSame(DisplayStatus::MajorOutage, $bars[4]->status);
        $this->assertSame(DisplayStatus::Operational, $bars[5]->status);
        $this->assertSame(DisplayStatus::Unknown, $bars[6]->status);
    }

    public function test_maintenance_in_progress_and_upcoming(): void
    {
        $group = $this->monitor('Database cluster', MonitorStatus::Up, group: true);
        $replica = $this->monitor('replica', MonitorStatus::Up, parent: $group);
        $web = $this->monitor('Web', MonitorStatus::Up);
        $mail = $this->monitor('Mail', MonitorStatus::Up);
        $this->item($group, expand: true, displayName: 'Databases');
        $this->item($web);
        $this->item($mail);
        $this->item($replica, displayName: 'Read replica');

        $active = $this->maintenance('Cluster upgrade', $this->now->subMinutes(10), $this->now->addHours(2), [$group], 'Rolling **restart**');
        $upcoming = $this->maintenance('Mail move', $this->now->addDays(3), $this->now->addDays(3)->addHour(), [$mail]);
        $this->maintenance('Too far', $this->now->addDays(10), $this->now->addDays(10)->addHour(), [$mail]);
        $this->maintenance('Over', $this->now->subDays(2), $this->now->subDay(), [$web]);
        $this->maintenance('Disabled', $this->now->subMinutes(5), $this->now->addHour(), [$web], active: false);
        $linked = $this->maintenance('Network notice', $this->now->addDay(), null, []);
        DB::table('status_page_links')->insert(['status_page_id' => $this->page->id, 'linkable_type' => 'maintenance', 'linkable_id' => $linked->id]);
        $weekly = Maintenance::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Weekly backup',
            'strategy' => MaintenanceStrategy::Weekly,
            'starts_at' => null,
            'ends_at' => null,
            'start_time' => '03:00',
            'duration_minutes' => 60,
            'weekdays' => [1],
        ]);
        $weekly->monitors()->attach($web);

        $view = $this->build();
        $items = $view->sections[0]->items;

        $this->assertSame(DisplayStatus::Maintenance, $items[0]->status);
        $this->assertSame(DisplayStatus::Maintenance, $items[0]->children[0]->status);
        $this->assertSame(DisplayStatus::Operational, $items[1]->status);
        $this->assertSame(DisplayStatus::Operational, $items[2]->status);
        $this->assertSame(DisplayStatus::Maintenance, $items[3]->status);
        $this->assertSame(DisplayStatus::Maintenance, $view->overall);
        $this->assertSame('maintenance', $view->overallLabelKey);

        $this->assertCount(1, $view->activeMaintenance);
        $m = $view->activeMaintenance[0];
        $this->assertSame($active->id, $m->id);
        $this->assertSame('Cluster upgrade', $m->title);
        $this->assertStringContainsString('<strong>restart</strong>', (string) $m->descriptionHtml);
        $this->assertTrue($m->startsAt->equalTo($this->now->subMinutes(10)));
        $this->assertNotNull($m->endsAt);
        $this->assertTrue($m->endsAt->equalTo($this->now->addHours(2)));
        $this->assertSame(['Databases', 'replica', 'Read replica'], $m->affectedComponents);

        $this->assertSame(['Network notice', 'Weekly backup', 'Mail move'], array_map(fn ($u) => $u->title, $view->upcomingMaintenance));
        $this->assertSame($upcoming->id, $view->upcomingMaintenance[2]->id);
        $this->assertSame(['Mail'], $view->upcomingMaintenance[2]->affectedComponents);
        $this->assertNull($view->upcomingMaintenance[0]->endsAt);
        $this->assertSame([], $view->upcomingMaintenance[0]->affectedComponents);
        // Monday 2026-11-09 03:00 Budapest (CET) is 02:00 UTC.
        $this->assertSame('2026-11-09 02:00:00', $view->upcomingMaintenance[1]->startsAt->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(['Web'], $view->upcomingMaintenance[1]->affectedComponents);
    }

    public function test_query_count_does_not_grow_with_items(): void
    {
        // Each population lives in its own workspace, so the second page shares no rows with the first.
        $small = $this->queriesFor($this->populate(10));
        $large = $this->queriesFor($this->populate(250));

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(12, $large);
    }

    public function test_monitor_cap_drops_excess_children_but_never_items(): void
    {
        $group = $this->monitor('Group', MonitorStatus::Up, group: true);
        foreach (['a', 'b', 'c', 'd'] as $name) {
            $this->monitor($name, MonitorStatus::Up, parent: $group);
        }
        $this->item($group, expand: true);
        $this->item($this->monitor('Web', MonitorStatus::Up));
        $this->item($this->monitor('Mail', MonitorStatus::Up));

        $view = (new StatusPageSnapshot(4))->build(StatusPage::query()->findOrFail($this->page->id), $this->now);

        $items = $view->sections[0]->items;
        $this->assertSame(['Group', 'Web', 'Mail'], array_map(fn (ItemView $i): string => $i->name, $items));
        $this->assertSame(['a'], array_map(fn (ItemView $c): string => $c->name, $items[0]->children));
    }

    public function test_expires_at_is_next_maintenance_boundary(): void
    {
        $web = $this->monitor('Web', MonitorStatus::Up);
        $this->item($web);
        $this->maintenance('Later', $this->now->addMinutes(5), $this->now->addMinutes(10), [$web]);
        $before = $this->build();
        $this->assertNull($before->expiresAt);

        $this->maintenance('Ends soon', $this->now->subMinutes(5), $this->now->addSeconds(45), [$web]);
        $this->maintenance('Starts soon', $this->now->addSeconds(30), $this->now->addHour(), [$web]);
        $this->maintenance('Not shown', $this->now->addSeconds(10), $this->now->addHour(), []);

        $after = $this->build();
        $expires = $after->expiresAt;
        $this->assertNotNull($expires);
        $this->assertTrue($expires->equalTo($this->now->addSeconds(30)), (string) $expires);
    }

    private function build(): PageView
    {
        $page = StatusPage::query()->findOrFail($this->page->id);

        return (new StatusPageSnapshot)->build($page, $this->now);
    }

    private function queriesFor(StatusPage $page): int
    {
        $page = StatusPage::query()->findOrFail($page->id);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $view = (new StatusPageSnapshot)->build($page, $this->now);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertNotSame([], $view->sections);

        return $count;
    }

    /**
     * A page with the given number of items, two of them expanded groups, plus incidents, maintenance and rollups.
     */
    private function populate(int $items): StatusPage
    {
        $workspace = Workspace::factory()->create(['timezone' => self::TZ]);
        $page = StatusPage::factory()->create(['workspace_id' => $workspace->id]);
        $sections = [
            StatusPageSection::factory()->create(['status_page_id' => $page->id, 'position' => 0]),
            StatusPageSection::factory()->create(['status_page_id' => $page->id, 'position' => 1]),
        ];
        $monitors = Monitor::factory()->count($items - 2)->create(['workspace_id' => $workspace->id]);
        $groups = Monitor::factory()->group()->count(2)->create(['workspace_id' => $workspace->id]);
        $children = [];
        foreach ($groups as $group) {
            foreach (Monitor::factory()->count(3)->create(['workspace_id' => $workspace->id, 'parent_id' => $group->id]) as $child) {
                $children[] = $child;
            }
        }
        $position = 0;
        foreach ($groups->concat($monitors) as $i => $monitor) {
            StatusPageItem::factory()->create([
                'status_page_section_id' => $sections[$i % 2]->id,
                'monitor_id' => $monitor->id,
                'position' => $position++,
                'expand_children' => $monitor->type->value === 'group',
            ]);
        }

        $today = $this->now->setTimezone(self::TZ)->startOfDay();
        $hour = intdiv($this->now->getTimestamp(), 3600) * 3600;
        $first = $monitors->firstOrFail();
        foreach ([$first, $children[0]] as $monitor) {
            $this->dayRollup($monitor, $today, up: 1440);
            $this->hourRollup($monitor, $hour, 100, 1);
        }

        $incident = Incident::factory()->create(['workspace_id' => $workspace->id, 'started_at' => $this->now->subHour()]);
        $incident->monitors()->attach([$first->id, $children[1]->id]);
        IncidentUpdate::factory()->create(['incident_id' => $incident->id]);
        $maintenance = Maintenance::factory()->create([
            'workspace_id' => $workspace->id,
            'starts_at' => $this->now->subMinute(),
            'ends_at' => $this->now->addHour(),
        ]);
        $maintenance->monitors()->attach([$groups->firstOrFail()->id]);

        return $page;
    }

    private function monitor(string $name, MonitorStatus $status, bool $group = false, ?Monitor $parent = null, bool $active = true): Monitor
    {
        $factory = Monitor::factory();
        if ($group) {
            $factory = $factory->group();
        }
        $monitor = $factory->create([
            'workspace_id' => $this->workspace->id,
            'name' => $name,
            'parent_id' => $parent?->id,
            'active' => $active,
        ]);
        DB::table('monitor_states')->where('monitor_id', $monitor->id)->update(['status' => $status->value]);

        return $monitor;
    }

    private function item(Monitor $monitor, bool $expand = false, ?string $displayName = null, ?string $description = null, bool $showUptime = true): StatusPageItem
    {
        return StatusPageItem::factory()->create([
            'status_page_section_id' => $this->section->id,
            'monitor_id' => $monitor->id,
            'display_name' => $displayName,
            'description' => $description,
            'position' => $this->position++,
            'expand_children' => $expand,
            'show_uptime' => $showUptime,
        ]);
    }

    /**
     * @param  list<Monitor>  $monitors
     */
    private function incident(string $title, IncidentImpact $impact, IncidentStatus $status, array $monitors, ?CarbonImmutable $resolvedAt = null): Incident
    {
        $incident = Incident::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => $title,
            'impact' => $impact,
            'status' => $status,
            'started_at' => ($resolvedAt ?? $this->now)->subHours(3),
            'resolved_at' => $resolvedAt,
        ]);
        $incident->monitors()->attach(array_map(fn (Monitor $m): int => $m->id, $monitors));

        return $incident;
    }

    /**
     * @param  list<Monitor>  $monitors
     */
    private function maintenance(string $title, CarbonImmutable $start, ?CarbonImmutable $end, array $monitors, ?string $description = null, bool $active = true): Maintenance
    {
        $maintenance = Maintenance::factory()->create([
            'workspace_id' => $this->workspace->id,
            'title' => $title,
            'description' => $description,
            'strategy' => MaintenanceStrategy::Once,
            'starts_at' => $start,
            'ends_at' => $end,
            'active' => $active,
        ]);
        $maintenance->monitors()->attach(array_map(fn (Monitor $m): int => $m->id, $monitors));

        return $maintenance;
    }

    private function dayRollup(Monitor $monitor, CarbonImmutable $localDay, int $up = 0, int $down = 0, int $degraded = 0, int $pending = 0, int $maintenance = 0): void
    {
        DB::table('check_rollups')->insert([
            'monitor_id' => $monitor->id,
            'bucket' => CheckRollup::BUCKET_DAY,
            'bucket_start' => $localDay->startOfDay()->getTimestamp(),
            'total' => $up + $down + $degraded + $pending + $maintenance,
            'up' => $up,
            'down' => $down,
            'degraded' => $degraded,
            'pending' => $pending,
            'maintenance' => $maintenance,
        ]);
    }

    private function hourRollup(Monitor $monitor, int $hourStart, int $latencySum, int $latencyCount): void
    {
        DB::table('check_rollups')->insert([
            'monitor_id' => $monitor->id,
            'bucket' => CheckRollup::BUCKET_HOUR,
            'bucket_start' => $hourStart,
            'total' => max($latencyCount, 1),
            'up' => max($latencyCount, 1),
            'latency_sum' => $latencySum,
            'latency_count' => $latencyCount,
        ]);
    }
}
