<?php

namespace Tests\Feature\Monitoring;

use App\Enums\MonitorStatus;
use App\Enums\WorkspaceRole;
use App\Events\ChecksRecorded;
use App\Jobs\SendNotification;
use App\Models\CheckResult;
use App\Models\Maintenance;
use App\Models\Monitor;
use App\Models\User;
use App\Models\Workspace;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Engine\ResultRecorder;
use App\Status\Maintenance\MaintenanceSync;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A group evaluation that another path than the recorder starts (an API change, a new monitor, a maintenance
 * boundary) writes a group result like the recorder's does, so it reaches the live heartbeat strip the same way.
 */
class GroupBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $t0;

    private Workspace $ws;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t0 = CarbonImmutable::parse('2026-11-06 10:00:00', 'UTC');
        CarbonImmutable::setTestNow($this->t0);
        $this->ws = Workspace::factory()->create();
        $this->editor = User::factory()->memberOf($this->ws, WorkspaceRole::Editor)->create();
        Queue::fake([SendNotification::class]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function api(string $path): string
    {
        return '/api/v1/workspaces/'.$this->ws->slug.$path;
    }

    private function record(Monitor $monitor, CheckOutcome $outcome): void
    {
        $this->app->make(ResultRecorder::class)->record([[MonitorSpec::fromModel($monitor->fresh() ?? $monitor), $outcome, CarbonImmutable::now(), null]]);
    }

    /**
     * A group in "all" mode that is down for one of its two children.
     *
     * @return array{0: Monitor, 1: Monitor}
     */
    private function downGroup(): array
    {
        $group = Monitor::factory()->for($this->ws)->group('all')->create();
        $sibling = Monitor::factory()->for($this->ws)->create(['retries' => 0, 'parent_id' => $group->id]);
        $child = Monitor::factory()->for($this->ws)->create(['retries' => 0, 'parent_id' => $group->id]);
        $this->record($sibling, CheckOutcome::up(10));
        $this->record($child, CheckOutcome::down('refused'));
        $this->assertSame(MonitorStatus::Down, $group->state()->firstOrFail()->status);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(30));
        Event::fake([ChecksRecorded::class]);

        return [$group, $child];
    }

    private function assertGroupBroadcast(Monitor $group, MonitorStatus $status, string $label = ''): void
    {
        $stored = CheckResult::where('monitor_id', $group->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame($status, $stored->status, $label);
        $checkedAt = $stored->checked_at->toIso8601ZuluString();

        $entries = [];
        Event::assertDispatched(ChecksRecorded::class, function (ChecksRecorded $e) use ($group, &$entries): bool {
            foreach ($e->checks as $check) {
                if ($check['monitor_id'] === $group->id) {
                    $entries[] = [$e->workspaceId, $check];
                }
            }

            return true;
        });
        $this->assertSame([[$this->ws->id, [
            'monitor_id' => $group->id, 'status' => $status->value, 'latency_ms' => null, 'checked_at' => $checkedAt,
        ]]], $entries, $label);
    }

    public function test_group_evaluations_from_api_changes_are_broadcast(): void
    {
        $paths = [
            'action pause' => fn (Monitor $child) => $this->actingAs($this->editor)->postJson($this->api("/monitors/{$child->id}/pause"))->assertOk(),
            'bulk pause' => fn (Monitor $child) => $this->actingAs($this->editor)->postJson($this->api('/monitors/bulk'), ['action' => 'pause', 'ids' => [$child->id]])->assertOk(),
            'bulk delete' => fn (Monitor $child) => $this->actingAs($this->editor)->postJson($this->api('/monitors/bulk'), ['action' => 'delete', 'ids' => [$child->id]])->assertOk(),
            'delete' => fn (Monitor $child) => $this->actingAs($this->editor)->deleteJson($this->api("/monitors/{$child->id}"))->assertNoContent(),
            'move' => fn (Monitor $child) => $this->actingAs($this->editor)->patchJson($this->api("/monitors/{$child->id}"), ['parent_id' => null])->assertOk(),
        ];

        foreach ($paths as $label => $change) {
            [$group, $child] = $this->downGroup();
            $change($child);
            $this->assertGroupBroadcast($group, MonitorStatus::Up, $label);
        }
    }

    public function test_group_evaluation_from_a_new_child_is_broadcast(): void
    {
        $group = Monitor::factory()->for($this->ws)->group('all')->create();
        $child = Monitor::factory()->for($this->ws)->create(['retries' => 0, 'parent_id' => $group->id]);
        $this->record($child, CheckOutcome::up(10));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(30));
        // A stale stored status, so the recompute that the new (pending, neutral) member starts writes a result.
        $group->state()->update(['status' => MonitorStatus::Down]);
        Event::fake([ChecksRecorded::class]);

        $this->actingAs($this->editor)->postJson($this->api('/monitors'), [
            'name' => 'Cron', 'type' => 'push', 'parent_id' => $group->id,
        ])->assertCreated();

        $this->assertGroupBroadcast($group, MonitorStatus::Up);
    }

    public function test_group_evaluation_at_a_maintenance_boundary_is_broadcast(): void
    {
        [$group] = $this->downGroup();
        $maintenance = Maintenance::factory()->create([
            'workspace_id' => $this->ws->id, 'starts_at' => '2026-11-06 10:00:40', 'ends_at' => '2026-11-06 11:00:00',
        ]);
        $maintenance->monitors()->attach([$group->id]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-06 10:01:00', 'UTC'));

        $this->app->make(MaintenanceSync::class)->run(CarbonImmutable::parse('2026-11-06 10:00:30', 'UTC'), CarbonImmutable::now());

        $this->assertGroupBroadcast($group, MonitorStatus::Maintenance);
    }
}
