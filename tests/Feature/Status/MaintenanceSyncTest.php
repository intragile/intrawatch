<?php

namespace Tests\Feature\Status;

use App\Enums\MonitorStatus;
use App\Jobs\SendNotification;
use App\Models\Maintenance;
use App\Models\Monitor;
use App\Models\Workspace;
use App\Status\Maintenance\MaintenanceSync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MaintenanceSyncTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = CarbonImmutable::parse('2026-11-06 10:01:00', 'UTC');
        CarbonImmutable::setTestNow($this->now);
        $this->ws = Workspace::factory()->create();
        Queue::fake([SendNotification::class]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  list<Monitor>  $targets
     */
    private function window(array $targets, string $start, ?string $end): Maintenance
    {
        $maintenance = Maintenance::factory()->create(['workspace_id' => $this->ws->id, 'starts_at' => $start, 'ends_at' => $end]);
        $maintenance->monitors()->attach(array_map(fn (Monitor $m): int => $m->id, $targets));

        return $maintenance;
    }

    private function requested(Monitor $monitor): mixed
    {
        return DB::table('monitors')->where('id', $monitor->id)->value('check_requested_at');
    }

    /**
     * @return array{requested: int, groups: int}
     */
    private function sync(string $from = '2026-11-06 10:00:00'): array
    {
        return $this->app->make(MaintenanceSync::class)->run(CarbonImmutable::parse($from, 'UTC'), $this->now);
    }

    public function test_sync_requests_checks_and_recomputes_groups_at_boundaries(): void
    {
        $group = Monitor::factory()->for($this->ws)->group('all')->create();
        $child = Monitor::factory()->for($this->ws)->create(['parent_id' => $group->id]);
        $child->state()->update(['status' => MonitorStatus::Down]);
        $leaf = Monitor::factory()->for($this->ws)->create();
        $push = Monitor::factory()->for($this->ws)->push()->create();
        $paused = Monitor::factory()->for($this->ws)->create(['active' => false]);
        $untouched = Monitor::factory()->for($this->ws)->create();
        $stamp = '2026-11-01 00:00:00';
        DB::table('monitors')->update(['updated_at' => $stamp]);

        // The window starts between the watermark and now; the group target brings its child along.
        $this->window([$group, $leaf, $push, $paused], '2026-11-06 10:00:30', '2026-11-06 11:00:00');

        $this->assertSame(['requested' => 2, 'groups' => 1], $this->sync());

        $this->assertNotNull($this->requested($leaf));
        $this->assertNotNull($this->requested($child));
        $this->assertNull($this->requested($push));
        $this->assertNull($this->requested($paused));
        $this->assertNull($this->requested($untouched));
        $this->assertNull($this->requested($group));
        $this->assertSame(0, Monitor::query()->where('updated_at', '!=', $stamp)->count(), 'monitors.updated_at stays untouched');
        $this->assertSame(MonitorStatus::Maintenance, $group->state()->firstOrFail()->status);
    }

    public function test_sync_handles_the_end_of_a_window_too(): void
    {
        $group = Monitor::factory()->for($this->ws)->group('all')->create();
        $child = Monitor::factory()->for($this->ws)->create(['parent_id' => $group->id]);
        $child->state()->update(['status' => MonitorStatus::Down]);
        $this->window([$group], '2026-11-06 09:00:00', '2026-11-06 10:00:30');
        $group->state()->update(['status' => MonitorStatus::Maintenance]);

        $this->assertSame(['requested' => 1, 'groups' => 1], $this->sync());

        $this->assertSame(MonitorStatus::Down, $group->state()->firstOrFail()->status);
    }

    public function test_sync_ignores_boundaries_outside_the_range(): void
    {
        $leaf = Monitor::factory()->for($this->ws)->create();
        $this->window([$leaf], '2026-11-06 09:00:00', '2026-11-06 11:00:00');

        $this->assertSame(['requested' => 0, 'groups' => 0], $this->sync());
        $this->assertNull($this->requested($leaf));
    }

    public function test_command_keeps_a_watermark_and_runs_each_boundary_once(): void
    {
        $leaf = Monitor::factory()->for($this->ws)->create();
        $this->window([$leaf], '2026-11-06 10:00:30', '2026-11-06 11:00:00');

        // Without a watermark the last minute is covered.
        $this->assertSame(0, Artisan::call('intrawatch:maintenance:sync'));
        $this->assertNotNull($this->requested($leaf));
        $this->assertSame($this->now->getTimestamp(), Cache::get('maintenance:sync:at'));

        DB::table('monitors')->update(['check_requested_at' => null]);
        CarbonImmutable::setTestNow($this->now->addSeconds(15));
        $this->assertSame(0, Artisan::call('intrawatch:maintenance:sync'));
        $this->assertNull($this->requested($leaf), 'the boundary is behind the watermark');

        // A stale watermark is capped to one hour back: a boundary 90 minutes ago is out of reach, 30 minutes ago is not.
        DB::table('monitors')->update(['check_requested_at' => null]);
        DB::table('maintenances')->delete();
        Cache::forever('maintenance:sync:at', $this->now->subHours(3)->getTimestamp());
        CarbonImmutable::setTestNow($this->now->addSeconds(30));
        $this->window([$leaf], '2026-11-06 08:00:00', '2026-11-06 08:31:00');
        $this->assertSame(0, Artisan::call('intrawatch:maintenance:sync'));
        $this->assertNull($this->requested($leaf), 'a boundary older than an hour is not processed');

        Cache::forever('maintenance:sync:at', $this->now->subHours(3)->getTimestamp());
        CarbonImmutable::setTestNow($this->now->addSeconds(40));
        $this->window([$leaf], '2026-11-06 09:00:00', '2026-11-06 09:31:00');
        $this->assertSame(0, Artisan::call('intrawatch:maintenance:sync'));
        $this->assertNotNull($this->requested($leaf), 'a boundary within the last hour is processed');
    }

    public function test_command_is_scheduled_every_fifteen_seconds(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'intrawatch:maintenance:sync'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertNotNull($event);
        $this->assertTrue($event->onOneServer);
        $this->assertSame(2, $event->expiresAt);
        $this->assertSame(15, $event->repeatSeconds);
    }
}
