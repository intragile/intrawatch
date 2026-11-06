<?php

namespace Tests\Feature\Monitoring;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\Tag;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonitorModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_monitor_creates_pending_state_and_hides_secrets(): void
    {
        $m = Monitor::factory()->http()->create(['secrets' => ['bearer_token' => 's3cr3t']]);

        $this->assertSame(MonitorStatus::Pending, $m->state->status);
        $this->assertSame(0, $m->state->consecutive_failures);
        $this->assertArrayNotHasKey('secrets', $m->toArray());
        $this->assertArrayNotHasKey('push_token', $m->toArray());
        $stored = DB::table('monitors')->value('secrets');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('s3cr3t', $stored);
        $this->assertSame(['bearer_token' => 's3cr3t'], $m->fresh()?->secrets);
    }

    public function test_inactive_monitor_starts_paused(): void
    {
        $m = Monitor::factory()->http()->create(['active' => false]);

        $this->assertSame(MonitorStatus::Paused, $m->state->status);
    }

    public function test_factory_states_cover_every_type(): void
    {
        $this->assertSame(MonitorType::Http, Monitor::factory()->http()->create()->type);
        $this->assertSame(MonitorType::Tcp, Monitor::factory()->tcp()->create()->type);
        $this->assertSame(MonitorType::Cron, Monitor::factory()->cron()->create()->type);
        $this->assertSame(MonitorType::Group, Monitor::factory()->group()->create()->type);

        $push = Monitor::factory()->push()->create();
        $this->assertSame(MonitorType::Push, $push->type);
        $this->assertSame(48, strlen((string) $push->push_token));
    }

    public function test_deleting_group_orphans_children(): void
    {
        $group = Monitor::factory()->group()->create();
        $child = Monitor::factory()->http()->create(['workspace_id' => $group->workspace_id, 'parent_id' => $group->id]);

        $this->assertTrue($group->children->contains($child));
        $this->assertTrue($child->parent?->is($group));

        $group->delete();

        $this->assertNull($child->fresh()?->parent_id);
    }

    public function test_deleting_workspace_cascades_to_monitors_states_and_tags(): void
    {
        $m = Monitor::factory()->http()->create();
        $tag = Tag::factory()->create(['workspace_id' => $m->workspace_id]);
        $m->tags()->attach($tag, ['value' => 'eu']);

        $m->workspace()->firstOrFail()->delete();

        $this->assertDatabaseCount('monitors', 0);
        $this->assertDatabaseCount('monitor_states', 0);
        $this->assertDatabaseCount('tags', 0);
        $this->assertDatabaseCount('monitor_tag', 0);
    }

    public function test_tags_carry_a_pivot_value_and_are_unique_per_workspace(): void
    {
        $m = Monitor::factory()->http()->create();
        $tag = Tag::factory()->create(['workspace_id' => $m->workspace_id, 'name' => 'prod']);
        $m->tags()->attach($tag, ['value' => 'eu']);

        $this->assertSame('eu', DB::table('monitor_tag')->value('value'));
        $this->assertTrue($tag->monitors->contains($m));

        // The same name is fine in another workspace.
        Tag::factory()->create(['workspace_id' => Workspace::factory()->create()->id, 'name' => 'prod']);

        $this->expectException(QueryException::class);
        Tag::factory()->create(['workspace_id' => $m->workspace_id, 'name' => 'prod']);
    }

    public function test_a_monitor_cannot_carry_the_same_tag_twice(): void
    {
        $m = Monitor::factory()->http()->create();
        $tag = Tag::factory()->create(['workspace_id' => $m->workspace_id]);
        $m->tags()->attach($tag);

        $this->expectException(QueryException::class);
        $m->tags()->attach($tag);
    }

    public function test_workspace_exposes_monitors_tags_and_a_utc_default_timezone(): void
    {
        $m = Monitor::factory()->http()->create();
        $tag = Tag::factory()->create(['workspace_id' => $m->workspace_id]);
        $workspace = $m->workspace()->firstOrFail();

        $this->assertSame('UTC', $workspace->timezone);
        $this->assertTrue($workspace->monitors->contains($m));
        $this->assertTrue($workspace->tags->contains($tag));
    }
}
