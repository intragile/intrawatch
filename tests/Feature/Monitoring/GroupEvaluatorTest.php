<?php

namespace Tests\Feature\Monitoring;

use App\Enums\MonitorStatus;
use App\Enums\WorkspaceRole;
use App\Events\MonitorStatusChanged;
use App\Models\CheckResult;
use App\Models\Monitor;
use App\Models\MonitorEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\GroupEvaluator;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Engine\ResultRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class GroupEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private function statusOf(Monitor $monitor): MonitorStatus
    {
        return $monitor->state()->firstOrFail()->status;
    }

    private function resultCount(Monitor $monitor): int
    {
        return CheckResult::where('monitor_id', $monitor->id)->count();
    }

    private function setStatus(Monitor $monitor, MonitorStatus $status): void
    {
        $monitor->state()->update(['status' => $status]);
    }

    /**
     * @param  list<MonitorStatus>  $statuses
     * @return array{0: Monitor, 1: list<Monitor>}
     */
    private function groupOf(string $mode, array $statuses, ?int $quorum = null): array
    {
        $workspace = Workspace::factory()->create();
        $group = Monitor::factory()->for($workspace)->group($mode, $quorum)->create();
        $children = [];
        foreach ($statuses as $status) {
            $child = Monitor::factory()->for($workspace)->create(['parent_id' => $group->id, 'retries' => 0]);
            $this->setStatus($child, $status);
            $children[] = $child;
        }

        return [$group, $children];
    }

    /**
     * @param  list<MonitorStatus>  $statuses
     */
    private function evaluate(string $mode, array $statuses, ?int $quorum = null): MonitorStatus
    {
        [$group] = $this->groupOf($mode, $statuses, $quorum);
        $this->app->make(GroupEvaluator::class)->recompute([$group->id]);

        return $this->statusOf($group);
    }

    public function test_all_mode(): void
    {
        $this->assertSame(MonitorStatus::Down, $this->evaluate('all', [MonitorStatus::Up, MonitorStatus::Down]));
        $this->assertSame(MonitorStatus::Degraded, $this->evaluate('all', [MonitorStatus::Up, MonitorStatus::Degraded]));
        $this->assertSame(MonitorStatus::Up, $this->evaluate('all', [MonitorStatus::Up, MonitorStatus::Pending]));
        $this->assertSame(MonitorStatus::Up, $this->evaluate('all', [MonitorStatus::Up, MonitorStatus::Up]));
    }

    public function test_any_mode_and_quorum_mode(): void
    {
        $this->assertSame(MonitorStatus::Up, $this->evaluate('any', [MonitorStatus::Down, MonitorStatus::Up]));
        $this->assertSame(MonitorStatus::Degraded, $this->evaluate('any', [MonitorStatus::Down, MonitorStatus::Degraded]));
        $this->assertSame(MonitorStatus::Down, $this->evaluate('any', [MonitorStatus::Down, MonitorStatus::Down]));
        $this->assertSame(MonitorStatus::Pending, $this->evaluate('any', [MonitorStatus::Pending]));
        $this->assertSame(MonitorStatus::Pending, $this->evaluate('any', [MonitorStatus::Pending, MonitorStatus::Down]));

        $this->assertSame(MonitorStatus::Up, $this->evaluate('quorum', [MonitorStatus::Up, MonitorStatus::Up, MonitorStatus::Down], 2));
        $this->assertSame(MonitorStatus::Down, $this->evaluate('quorum', [MonitorStatus::Up, MonitorStatus::Down, MonitorStatus::Down], 2));
        $this->assertSame(MonitorStatus::Up, $this->evaluate('quorum', [MonitorStatus::Up, MonitorStatus::Degraded, MonitorStatus::Down], 2));
    }

    public function test_paused_children_are_ignored_and_empty_group_is_pending(): void
    {
        [$group, $children] = $this->groupOf('all', [MonitorStatus::Up, MonitorStatus::Down]);
        $children[1]->update(['active' => false]);
        $this->setStatus($children[1], MonitorStatus::Paused);
        $this->setStatus($children[0], MonitorStatus::Up);

        $this->app->make(GroupEvaluator::class)->recompute([$group->id]);
        $this->assertSame(MonitorStatus::Up, $this->statusOf($group));
        $this->assertSame('1/1 children available', CheckResult::where('monitor_id', $group->id)->firstOrFail()->message);

        $this->setStatus($children[0], MonitorStatus::Paused);
        $children[0]->update(['active' => false]);
        $this->app->make(GroupEvaluator::class)->recompute([$group->id]);
        $this->assertSame(MonitorStatus::Pending, $this->statusOf($group));
    }

    public function test_status_change_writes_event_result_and_transition(): void
    {
        [$group] = $this->groupOf('all', [MonitorStatus::Down]);

        $transitions = $this->app->make(GroupEvaluator::class)->recompute([$group->id]);

        $this->assertCount(1, $transitions);
        $this->assertTrue($transitions[0]->changed);
        $this->assertSame(MonitorStatus::Pending, $transitions[0]->from);
        $this->assertSame(MonitorStatus::Down, $transitions[0]->effective);
        $event = MonitorEvent::where('monitor_id', $group->id)->firstOrFail();
        $this->assertSame(MonitorEvent::KIND_STATUS_CHANGED, $event->kind);
        $result = CheckResult::where('monitor_id', $group->id)->firstOrFail();
        $this->assertSame('down', $result->status->value);
        $this->assertNull($result->latency_ms);
        $this->assertNull($result->drift_ms);
        $this->assertSame('0/1 children available', $result->message);
    }

    public function test_group_result_rows_follow_the_group_interval(): void
    {
        [$group] = $this->groupOf('all', [MonitorStatus::Up]);
        $group->update(['interval_seconds' => 60]);
        $evaluator = $this->app->make(GroupEvaluator::class);

        $counts = [];
        $evaluator->recompute([$group->id]);
        $counts[] = $this->resultCount($group);

        $evaluator->recompute([$group->id]);
        $evaluator->recompute([$group->id]);
        $counts[] = $this->resultCount($group);
        $this->assertSame([], $evaluator->recompute([$group->id]));

        $this->travel(61)->seconds();
        $evaluator->recompute([$group->id]);
        $counts[] = $this->resultCount($group);

        $this->assertSame([1, 1, 2], $counts);
        $this->assertSame(1, MonitorEvent::where('monitor_id', $group->id)->count());
    }

    public function test_nested_groups_propagate_within_one_record_call(): void
    {
        Event::fake([MonitorStatusChanged::class]);
        $workspace = Workspace::factory()->create();
        $g1 = Monitor::factory()->for($workspace)->group()->create();
        $g2 = Monitor::factory()->for($workspace)->group()->create(['parent_id' => $g1->id]);
        $m = Monitor::factory()->for($workspace)->create(['parent_id' => $g2->id, 'retries' => 0]);
        foreach ([$g1, $g2, $m] as $monitor) {
            $this->setStatus($monitor, MonitorStatus::Up);
        }

        $batch = $this->app->make(ResultRecorder::class)->record([
            [MonitorSpec::fromModel($m), CheckOutcome::down('boom'), CarbonImmutable::now(), 1],
        ]);

        $this->assertSame(MonitorStatus::Down, $this->statusOf($g2));
        $this->assertSame(MonitorStatus::Down, $this->statusOf($g1));
        $this->assertCount(2, $batch->groupTransitions);
        $this->assertSame(2, MonitorEvent::whereIn('monitor_id', [$g1->id, $g2->id])->where('kind', MonitorEvent::KIND_STATUS_CHANGED)->count());
        Event::assertDispatchedTimes(MonitorStatusChanged::class, 3);
    }

    public function test_the_whole_ancestor_set_is_locked_once_in_id_order(): void
    {
        $workspace = Workspace::factory()->create();
        $root = Monitor::factory()->for($workspace)->group()->create();
        $middle = Monitor::factory()->for($workspace)->group()->create(['parent_id' => $root->id]);
        $leaf = Monitor::factory()->for($workspace)->group()->create(['parent_id' => $middle->id]);
        Monitor::factory()->for($workspace)->create(['parent_id' => $root->id]);
        Monitor::factory()->for($workspace)->create(['parent_id' => $leaf->id]);

        $stateLocks = [];
        $groupLocks = [];
        DB::listen(function ($query) use (&$stateLocks, &$groupLocks): void {
            if (! str_starts_with(strtolower($query->sql), 'select')) {
                return;
            }
            $ids = array_values(array_map(intval(...), array_filter($query->bindings, is_int(...))));
            if (preg_match('/from [`"]?monitor_states[`"]?/i', $query->sql) === 1) {
                $stateLocks[] = $ids;
            } elseif (preg_match('/from [`"]?monitors[`"]? where [`"]?id[`"]? in .* [`"]?type[`"]? = /i', $query->sql) === 1) {
                $groupLocks[] = $ids;
            }
        });

        // A batch whose parents sit at different depths: a child of the root and a child of the leaf.
        $this->app->make(GroupEvaluator::class)->recompute([$leaf->id, $root->id]);

        $ancestors = [$root->id, $middle->id, $leaf->id];
        $this->assertSame([$ancestors], $stateLocks, 'every group state row is locked in one statement, in id order');
        $this->assertSame($ancestors, $groupLocks[0] ?? null, 'the group rows are share-locked first, in id order');
    }

    public function test_cyclic_data_terminates(): void
    {
        $workspace = Workspace::factory()->create();
        $a = Monitor::factory()->for($workspace)->group()->create();
        $b = Monitor::factory()->for($workspace)->group()->create(['parent_id' => $a->id]);
        DB::table('monitors')->where('id', $a->id)->update(['parent_id' => $b->id]);

        $this->app->make(GroupEvaluator::class)->recompute([$a->id]);

        $this->assertSame(MonitorStatus::Pending, $this->statusOf($a));
    }

    public function test_moving_child_out_recomputes_old_parent(): void
    {
        Event::fake([MonitorStatusChanged::class]);
        $workspace = Workspace::factory()->create();
        $user = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();
        $group = Monitor::factory()->for($workspace)->group()->create();
        $child = Monitor::factory()->for($workspace)->create(['parent_id' => $group->id]);
        $this->setStatus($child, MonitorStatus::Down);
        $this->app->make(GroupEvaluator::class)->recompute([$group->id]);
        $this->assertSame(MonitorStatus::Down, $this->statusOf($group));

        $this->actingAs($user)->patchJson("/api/v1/workspaces/{$workspace->slug}/monitors/{$child->id}", ['parent_id' => null])
            ->assertOk();

        $this->assertSame(MonitorStatus::Pending, $this->statusOf($group));
        Event::assertDispatched(MonitorStatusChanged::class, fn (MonitorStatusChanged $e): bool => $e->monitorId === $group->id
            && $e->to === MonitorStatus::Pending);
    }

    public function test_pause_resume_and_delete_recompute_the_parent(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();
        $group = Monitor::factory()->for($workspace)->group()->create();
        $up = Monitor::factory()->for($workspace)->create(['parent_id' => $group->id]);
        $down = Monitor::factory()->for($workspace)->create(['parent_id' => $group->id]);
        $this->setStatus($up, MonitorStatus::Up);
        $this->setStatus($down, MonitorStatus::Down);
        $evaluator = $this->app->make(GroupEvaluator::class);
        $evaluator->recompute([$group->id]);
        $this->assertSame(MonitorStatus::Down, $this->statusOf($group));
        $base = "/api/v1/workspaces/{$workspace->slug}/monitors";

        $this->actingAs($user)->patchJson("{$base}/{$down->id}", ['active' => false])->assertOk();
        $this->assertSame(MonitorStatus::Up, $this->statusOf($group));

        $this->actingAs($user)->postJson("{$base}/bulk", ['action' => 'resume', 'ids' => [$down->id]])->assertOk();
        $this->setStatus($down, MonitorStatus::Down);
        $evaluator->recompute([$group->id]);
        $this->assertSame(MonitorStatus::Down, $this->statusOf($group));

        $this->actingAs($user)->deleteJson("{$base}/{$down->id}")->assertNoContent();
        $this->assertSame(MonitorStatus::Up, $this->statusOf($group));

        $this->setStatus($up, MonitorStatus::Down);
        $evaluator->recompute([$group->id]);
        $this->actingAs($user)->postJson("{$base}/bulk", ['action' => 'pause', 'ids' => [$up->id]])->assertOk();
        $this->assertSame(MonitorStatus::Pending, $this->statusOf($group));
    }

    public function test_changing_group_mode_recomputes_the_group(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();
        [$group] = $this->groupOf('all', [MonitorStatus::Up, MonitorStatus::Down]);
        $group->update(['workspace_id' => $workspace->id]);
        $this->app->make(GroupEvaluator::class)->recompute([$group->id]);
        $this->assertSame(MonitorStatus::Down, $this->statusOf($group));

        $this->actingAs($user)->patchJson("/api/v1/workspaces/{$workspace->slug}/monitors/{$group->id}", ['config' => ['mode' => 'any']])
            ->assertOk();

        $this->assertSame(MonitorStatus::Up, $this->statusOf($group));
    }

    public function test_creating_a_child_recomputes_the_parent(): void
    {
        Event::fake([MonitorStatusChanged::class]);
        $workspace = Workspace::factory()->create();
        $user = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();
        $group = Monitor::factory()->for($workspace)->group()->create();
        $this->app->make(GroupEvaluator::class)->recompute([$group->id]);
        $this->assertSame(MonitorStatus::Pending, $this->statusOf($group));

        $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->slug}/monitors", [
            'name' => 'Child', 'type' => 'http', 'config' => ['url' => 'https://example.test'], 'parent_id' => $group->id,
        ])->assertCreated();

        $this->assertSame(MonitorStatus::Pending, $this->statusOf($group));
        Event::assertNotDispatched(MonitorStatusChanged::class, fn (MonitorStatusChanged $e): bool => $e->monitorId === $group->id);
    }
}
