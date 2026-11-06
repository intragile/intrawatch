<?php

namespace Tests\Feature\Monitoring;

use App\Enums\MonitorStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\PurgeMonitorData;
use App\Models\AuditEvent;
use App\Models\Monitor;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class MonitorBulkTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ws = Workspace::factory()->create();
        $this->editor = User::factory()->memberOf($this->ws, WorkspaceRole::Editor)->create();
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function bulk(array $body): TestResponse
    {
        return $this->actingAs($this->editor)->postJson("/api/v1/workspaces/{$this->ws->slug}/monitors/bulk", $body);
    }

    public function test_bulk_ignores_foreign_ids_and_caps_at_500(): void
    {
        $own = Monitor::factory()->for($this->ws)->count(2)->create();
        $foreign = Monitor::factory()->create();

        $this->bulk(['action' => 'pause', 'ids' => [...$own->pluck('id')->all(), $foreign->id, 999999]])
            ->assertOk()->assertJson(['affected' => 2]);

        foreach ($own as $m) {
            $reloaded = Monitor::findOrFail($m->id);
            $this->assertFalse($reloaded->active);
            $this->assertSame(MonitorStatus::Paused, $reloaded->state->status);
        }
        $this->assertTrue($foreign->fresh()?->active);
        $this->assertSame(2, AuditEvent::where('action', 'monitor.paused')->count());

        $this->bulk(['action' => 'pause', 'ids' => range(1, 501)])->assertUnprocessable()->assertJsonValidationErrors(['ids']);
        $this->bulk(['action' => 'pause', 'ids' => []])->assertUnprocessable();
        $this->bulk(['action' => 'explode', 'ids' => [1]])->assertUnprocessable();
    }

    public function test_bulk_resume_delete_tag_and_untag(): void
    {
        Queue::fake();
        $a = Monitor::factory()->for($this->ws)->inactive()->create();
        $b = Monitor::factory()->for($this->ws)->create();
        $tag = Tag::factory()->for($this->ws)->create();
        $foreignTag = Tag::factory()->create();

        $this->bulk(['action' => 'resume', 'ids' => [$a->id]])->assertOk()->assertJson(['affected' => 1]);
        $this->assertSame(MonitorStatus::Pending, $a->fresh()?->state->status);

        $this->bulk(['action' => 'tag', 'ids' => [$a->id, $b->id]])->assertUnprocessable()->assertJsonValidationErrors(['tag_id']);
        $this->bulk(['action' => 'tag', 'ids' => [$a->id], 'tag_id' => $foreignTag->id])->assertUnprocessable();

        $this->bulk(['action' => 'tag', 'ids' => [$a->id, $b->id], 'tag_id' => $tag->id])->assertOk()->assertJson(['affected' => 2]);
        $this->assertSame(2, $tag->monitors()->count());
        $this->bulk(['action' => 'untag', 'ids' => [$a->id], 'tag_id' => $tag->id])->assertOk()->assertJson(['affected' => 1]);
        $this->assertSame(1, $tag->monitors()->count());

        $this->bulk(['action' => 'delete', 'ids' => [$a->id, $b->id]])->assertOk()->assertJson(['affected' => 2]);
        $this->assertDatabaseCount('monitors', 0);
        Queue::assertPushed(PurgeMonitorData::class, 2);
        $this->assertSame(2, AuditEvent::where('action', 'monitor.deleted')->count());
    }

    public function test_viewer_cannot_bulk(): void
    {
        $viewer = User::factory()->memberOf($this->ws, WorkspaceRole::Viewer)->create();
        $this->actingAs($viewer)->postJson("/api/v1/workspaces/{$this->ws->slug}/monitors/bulk", ['action' => 'pause', 'ids' => [1]])
            ->assertForbidden();
    }
}
