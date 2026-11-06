<?php

namespace Tests\Feature\Monitoring;

use App\Enums\WorkspaceRole;
use App\Models\Monitor;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AssertsWorkspaceIsolation;
use Tests\TestCase;

class MonitorHistoryApiTest extends TestCase
{
    use AssertsWorkspaceIsolation;
    use RefreshDatabase;

    private Workspace $ws;

    private User $viewer;

    private Monitor $monitor;

    private CarbonImmutable $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ws = Workspace::factory()->create();
        $this->viewer = User::factory()->memberOf($this->ws, WorkspaceRole::Viewer)->create();
        $this->monitor = Monitor::factory()->create(['workspace_id' => $this->ws->id]);
        $this->base = CarbonImmutable::parse('2026-03-10 10:00:00', 'UTC');
    }

    private function url(string $path, ?Monitor $monitor = null, ?Workspace $workspace = null): string
    {
        $monitor ??= $this->monitor;
        $workspace ??= $this->ws;

        return "/api/v1/workspaces/{$workspace->slug}/monitors/{$monitor->id}/{$path}";
    }

    private function seedHistory(Monitor $monitor): void
    {
        $statuses = ['up', 'down', 'up', 'degraded', 'up'];
        foreach ($statuses as $i => $status) {
            $at = $this->base->addMinutes($i);
            DB::table('check_results')->insert([
                'monitor_id' => $monitor->id, 'status' => $status, 'latency_ms' => 100 + $i,
                'message' => $status === 'down' ? 'Connection refused' : null,
                'checked_at' => $at->format('Y-m-d H:i:s'), 'bucket_minute' => $at->getTimestamp(),
            ]);
            DB::table('check_rollups')->insert([
                'monitor_id' => $monitor->id, 'bucket' => 'minute', 'bucket_start' => $at->getTimestamp(),
                'total' => 1, 'up' => $status === 'up' ? 1 : 0, 'down' => $status === 'down' ? 1 : 0,
                'degraded' => $status === 'degraded' ? 1 : 0, 'pending' => 0, 'maintenance' => 0,
                'latency_min' => 100 + $i, 'latency_max' => 100 + $i, 'latency_avg' => 100 + $i,
                'latency_sum' => 100 + $i, 'latency_count' => 1,
            ]);
            DB::table('monitor_events')->insert([
                'workspace_id' => $monitor->workspace_id, 'monitor_id' => $monitor->id,
                'kind' => $i % 2 === 0 ? 'status_changed' : 'changed', 'message' => "Event {$i}",
                'created_at' => $at->format('Y-m-d H:i:s'),
            ]);
        }
        DB::table('check_rollups')->insert([
            'monitor_id' => $monitor->id, 'bucket' => 'hour', 'bucket_start' => $this->base->getTimestamp(),
            'total' => 5, 'up' => 3, 'down' => 1, 'degraded' => 1, 'pending' => 0, 'maintenance' => 0,
            'latency_min' => 100, 'latency_max' => 104, 'latency_avg' => 102, 'latency_sum' => 510, 'latency_count' => 5,
        ]);
    }

    /**
     * Follow next_cursor until the end and return every page's items.
     *
     * @return list<array<mixed>>
     */
    private function allPages(string $url): array
    {
        $items = [];
        $cursor = null;
        $pages = 0;
        do {
            $separator = str_contains($url, '?') ? '&' : '?';
            $response = $this->actingAs($this->viewer)
                ->getJson($url.$separator.'limit=2'.(is_string($cursor) ? '&cursor='.urlencode($cursor) : ''))
                ->assertOk();
            $data = $response->json('data');
            $this->assertIsArray($data);
            foreach ($data as $item) {
                $this->assertIsArray($item);
                $items[] = $item;
            }
            $cursor = $response->json('meta.next_cursor');
            $pages++;
        } while ($cursor !== null && $pages < 10);

        return $items;
    }

    public function test_history_endpoints_paginate_and_are_isolated(): void
    {
        $this->seedHistory($this->monitor);
        $otherMonitor = Monitor::factory()->create(['workspace_id' => $this->ws->id]);
        $this->seedHistory($otherMonitor);

        // Results: newest first, across pages, only this monitor's.
        $results = $this->allPages($this->url('results'));
        $this->assertCount(5, $results);
        $this->assertSame($this->base->addMinutes(4)->toIso8601String(), $results[0]['checked_at']);
        $this->assertSame(['up', 'degraded', 'up', 'down', 'up'], array_column($results, 'status'));
        $this->assertSame('Connection refused', $results[3]['message']);

        $from = urlencode($this->base->addMinute()->toIso8601String());
        $to = urlencode($this->base->addMinutes(3)->toIso8601String());
        $this->actingAs($this->viewer)->getJson($this->url("results?from={$from}&to={$to}"))
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', 'up')
            ->assertJsonPath('data.1.status', 'down');
        $this->actingAs($this->viewer)->getJson($this->url('results?status=down'))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->viewer)->getJson($this->url('results?status=bogus'))->assertUnprocessable();
        $this->actingAs($this->viewer)->getJson($this->url('results?from=yesterday-ish'))->assertUnprocessable();

        // Rollups: oldest first, with availability.
        $minutes = $this->allPages($this->url('rollups?bucket=minute'));
        $this->assertCount(5, $minutes);
        $this->assertSame($this->base->toIso8601String(), $minutes[0]['bucket_start']);
        $this->assertEquals(1, $minutes[0]['availability']);
        $this->assertEquals(0, $minutes[1]['availability']);
        $this->assertEquals(1, $minutes[3]['availability']);
        $this->assertSame(
            ['bucket_start', 'total', 'up', 'down', 'degraded', 'pending', 'maintenance', 'availability', 'latency_min', 'latency_max', 'latency_avg'],
            array_keys($minutes[0]),
        );
        $this->actingAs($this->viewer)->getJson($this->url('rollups?bucket=hour'))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.total', 5)
            ->assertJsonPath('data.0.latency_avg', 102)
            ->assertJsonPath('data.0.availability', 0.8);
        $this->actingAs($this->viewer)
            ->getJson($this->url('rollups?bucket=minute&from='.urlencode($this->base->addMinutes(3)->toIso8601String())))
            ->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($this->viewer)->getJson($this->url('rollups?bucket=week'))->assertUnprocessable();
        $this->actingAs($this->viewer)->getJson($this->url('rollups'))->assertUnprocessable();

        // Events: newest first, filterable by kind.
        $events = $this->allPages($this->url('events'));
        $this->assertSame(['Event 4', 'Event 3', 'Event 2', 'Event 1', 'Event 0'], array_column($events, 'message'));
        $this->actingAs($this->viewer)->getJson($this->url('events?kind=changed'))
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.message', 'Event 3');

        // Isolation: a monitor of another workspace is a 404 through either workspace slug.
        [$actor, $own, $foreign] = $this->crossWorkspaceFixture();
        $foreignMonitor = Monitor::factory()->create(['workspace_id' => $foreign->id]);
        $this->seedHistory($foreignMonitor);
        foreach (['results', 'rollups?bucket=minute', 'events'] as $path) {
            $this->assertCrossWorkspaceNotFound($actor, 'GET', $this->url($path, $foreignMonitor, $own));
            $this->assertCrossWorkspaceNotFound($actor, 'GET', $this->url($path, $foreignMonitor, $foreign));
        }
    }

    public function test_history_needs_monitors_read_ability(): void
    {
        $token = $this->viewer->createToken('t', ['audit:read']);
        $token->accessToken->forceFill(['workspace_id' => $this->ws->id])->save();
        $readToken = $this->viewer->createToken('r', ['monitors:read']);
        $readToken->accessToken->forceFill(['workspace_id' => $this->ws->id])->save();

        foreach (['results', 'rollups?bucket=day', 'events'] as $path) {
            $this->app['auth']->forgetGuards();
            $this->withToken($token->plainTextToken)->getJson($this->url($path))->assertForbidden();
            $this->app['auth']->forgetGuards();
            $this->withToken($readToken->plainTextToken)->getJson($this->url($path))->assertOk();
        }
    }
}
