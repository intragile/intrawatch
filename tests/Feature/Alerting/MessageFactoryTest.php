<?php

namespace Tests\Feature\Alerting;

use App\Alerting\Messages\MessageFactory;
use App\Enums\AlertKind;
use App\Enums\MonitorStatus;
use App\Models\AlertEvent;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MessageFactoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function event(Workspace $workspace, ?Monitor $monitor, AlertKind $kind, array $attributes = []): AlertEvent
    {
        return AlertEvent::query()->create(array_merge([
            'workspace_id' => $workspace->id,
            'monitor_id' => $monitor?->id,
            'kind' => $kind,
            'status' => MonitorStatus::Down,
            'previous_status' => MonitorStatus::Up,
            'message' => 'HTTP 503',
            'created_at' => Carbon::parse('2026-07-01 10:30:00', 'UTC'),
        ], $attributes));
    }

    public function test_single_event_uses_default_and_channel_templates(): void
    {
        config(['app.url' => 'https://iw.test']);
        $workspace = Workspace::factory()->create(['name' => 'Acme']);
        $monitor = Monitor::factory()->for($workspace)->http('https://x.test/health')->create(['name' => 'Web']);
        $channel = NotificationChannel::factory()->slack()->create(['workspace_id' => $workspace->id]);
        $event = $this->event($workspace, $monitor, AlertKind::Down);

        $out = (new MessageFactory)->forEvents($channel, [$event]);
        $this->assertFalse($out->digest);
        $this->assertSame('[DOWN] Web', $out->title);
        $this->assertSame("Web (https://x.test/health) is down: HTTP 503\nhttps://iw.test/monitors/{$monitor->id}", $out->body);
        $this->assertSame('critical', $out->severity);

        $channel->templates = ['title' => '{{ workspace.name }}: {{ kind }}', 'body' => '{{ status }} was {{ previous_status }}'];
        $out = (new MessageFactory)->forEvents($channel, [$event]);
        $this->assertSame('Acme: down', $out->title);
        $this->assertSame('down was up', $out->body);
    }

    public function test_up_change_and_deleted_monitor_rendering(): void
    {
        $workspace = Workspace::factory()->create();
        $monitor = Monitor::factory()->for($workspace)->create(['name' => 'Web']);
        $channel = NotificationChannel::factory()->slack()->create(['workspace_id' => $workspace->id]);

        $up = $this->event($workspace, $monitor, AlertKind::Up, ['duration_seconds' => 725]);
        $this->assertStringContainsString('is up again after 12 min 5 s.', (new MessageFactory)->forEvents($channel, [$up])->body);

        $changed = $this->event($workspace, $monitor, AlertKind::Changed, [
            'message' => 'Certificate changed',
            'changes' => [['key' => 'issuer', 'old' => null, 'new' => 'R3'], ['key' => 'days', 'old' => 30, 'new' => 29]],
        ]);
        $body = (new MessageFactory)->forEvents($channel, [$changed])->body;
        $this->assertStringContainsString("Web: Certificate changed\nissuer: - → R3\ndays: 30 → 29\n", $body);

        $orphan = $this->event($workspace, null, AlertKind::Down);
        $out = (new MessageFactory)->forEvents($channel, [$orphan]);
        $this->assertSame('[DOWN] Deleted monitor', $out->title);
        $this->assertSame(rtrim(Config::string('app.url'), '/'), $out->items[0]->url);
    }

    public function test_digest_lists_twenty_and_loads_monitors_in_one_query(): void
    {
        $workspace = Workspace::factory()->create(['name' => 'Acme']);
        $channel = NotificationChannel::factory()->slack()->create(['workspace_id' => $workspace->id]);
        $events = [];
        foreach (Monitor::factory()->count(22)->for($workspace)->create() as $i => $monitor) {
            $events[] = $this->event($workspace, $monitor, $i === 0 ? AlertKind::Up : AlertKind::Down, ['message' => "m{$i}"]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $out = (new MessageFactory)->forEvents($channel, $events);
        $monitorQueries = array_filter(DB::getQueryLog(), static fn (array $q): bool => str_contains((string) $q['query'], 'from "monitors"') || str_contains((string) $q['query'], 'from `monitors`'));
        $this->assertCount(1, $monitorQueries);

        $this->assertTrue($out->digest);
        $this->assertSame('22 alerts in Acme', $out->title);
        $this->assertSame('critical', $out->severity);
        $this->assertCount(22, $out->items);
        $lines = explode("\n", $out->body);
        $this->assertCount(21, $lines);
        $this->assertSame('[UP] '.$events[0]->monitor?->name.': m0', $lines[0]);
        $this->assertSame('… and 2 more', $lines[20]);
    }

    public function test_digest_severity_is_the_most_severe_item(): void
    {
        $workspace = Workspace::factory()->create();
        $monitor = Monitor::factory()->for($workspace)->create();
        $channel = NotificationChannel::factory()->slack()->create(['workspace_id' => $workspace->id]);

        $out = (new MessageFactory)->forEvents($channel, [
            $this->event($workspace, $monitor, AlertKind::Up),
            $this->event($workspace, $monitor, AlertKind::Degraded),
        ]);
        $this->assertSame('warning', $out->severity);
    }

    public function test_at_local_uses_workspace_timezone(): void
    {
        $workspace = Workspace::factory()->create(['timezone' => 'Europe/Budapest']);
        $monitor = Monitor::factory()->for($workspace)->create();
        $channel = NotificationChannel::factory()->slack()->create([
            'workspace_id' => $workspace->id,
            'templates' => ['body' => '{{ at }} | {{ at_local }}'],
        ]);

        $out = (new MessageFactory)->forEvents($channel, [$this->event($workspace, $monitor, AlertKind::Down)]);
        $this->assertSame('2026-07-01T10:30:00+00:00 | 2026-07-01 12:30:00 CEST', $out->body);
    }

    public function test_test_notification(): void
    {
        $channel = NotificationChannel::factory()->slack()->create();
        $out = (new MessageFactory)->test($channel);

        $this->assertSame('[TEST] IntraWatch test', $out->title);
        $this->assertSame("This is a test notification from IntraWatch.\n".rtrim(Config::string('app.url'), '/'), $out->body);
        $this->assertSame('info', $out->severity);
        $this->assertSame('-', $out->items[0]->target);
    }
}
