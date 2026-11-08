<?php

namespace Tests\Feature\Monitoring;

use App\Enums\WorkspaceRole;
use App\Models\Monitor;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonitorRecentBeatsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ws = Workspace::factory()->create();
        $this->viewer = User::factory()->memberOf($this->ws, WorkspaceRole::Viewer)->create();
    }

    /**
     * @param  list<string>  $statuses  Oldest first, one minute apart.
     */
    private function seedBeats(Monitor $monitor, array $statuses): void
    {
        $base = CarbonImmutable::parse('2026-03-10 10:00:00', 'UTC');
        foreach ($statuses as $i => $status) {
            $at = $base->addMinutes($i);
            DB::table('check_results')->insert([
                'monitor_id' => $monitor->id,
                'status' => $status,
                'latency_ms' => $status === 'down' ? null : 100 + $i,
                'checked_at' => $at,
                'bucket_minute' => intdiv($at->getTimestamp(), 60),
            ]);
        }
    }

    /**
     * @return array<int, list<array<mixed>>> recent_beats keyed by monitor id.
     */
    private function beatsById(string $url): array
    {
        $data = $this->actingAs($this->viewer)->getJson($url)->assertOk()->json('data');
        $this->assertIsArray($data);
        $byId = [];
        foreach ($data as $item) {
            $this->assertIsArray($item);
            $this->assertIsInt($item['id']);
            $this->assertIsArray($item['recent_beats']);
            $beats = [];
            foreach ($item['recent_beats'] as $beat) {
                $this->assertIsArray($beat);
                $beats[] = $beat;
            }
            $byId[$item['id']] = $beats;
        }

        return $byId;
    }

    public function test_list_returns_the_newest_beats_oldest_first_per_monitor(): void
    {
        $a = Monitor::factory()->create(['workspace_id' => $this->ws->id]);
        $b = Monitor::factory()->create(['workspace_id' => $this->ws->id]);
        $quiet = Monitor::factory()->create(['workspace_id' => $this->ws->id]);
        $this->seedBeats($a, ['up', 'up', 'down', 'up', 'degraded']);
        $this->seedBeats($b, ['down', 'up']);

        $byId = $this->beatsById("/api/v1/workspaces/{$this->ws->slug}/monitors?beats=3");

        $this->assertSame(['down', 'up', 'degraded'], array_column($byId[$a->id], 'status'));
        $this->assertSame([null, 103, 104], array_column($byId[$a->id], 'latency_ms'));
        $this->assertSame(['down', 'up'], array_column($byId[$b->id], 'status'));
        $this->assertSame([], $byId[$quiet->id]);
        $checkedAt = $byId[$a->id][2]['checked_at'];
        $this->assertIsString($checkedAt);
        $this->assertStringStartsWith('2026-03-10T10:04:00', $checkedAt);
    }

    public function test_beats_are_null_unless_asked_for_and_the_count_is_bounded(): void
    {
        $monitor = Monitor::factory()->create(['workspace_id' => $this->ws->id]);
        $this->seedBeats($monitor, ['up']);
        $url = "/api/v1/workspaces/{$this->ws->slug}/monitors";

        $this->actingAs($this->viewer)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.0.recent_beats', null);

        $this->getJson("{$url}?beats=0")->assertUnprocessable()->assertJsonValidationErrors('beats');
        $this->getJson("{$url}?beats=51")->assertUnprocessable()->assertJsonValidationErrors('beats');
    }

    public function test_beats_stay_inside_the_workspace(): void
    {
        $other = Workspace::factory()->create();
        $foreign = Monitor::factory()->create(['workspace_id' => $other->id]);
        $this->seedBeats($foreign, ['down', 'down']);
        $mine = Monitor::factory()->create(['workspace_id' => $this->ws->id]);

        $byId = $this->beatsById("/api/v1/workspaces/{$this->ws->slug}/monitors?beats=10");

        $this->assertSame([$mine->id], array_keys($byId));
        $this->assertSame([], $byId[$mine->id]);
    }
}
