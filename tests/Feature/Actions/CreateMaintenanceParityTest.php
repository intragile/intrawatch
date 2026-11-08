<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\Maintenances\CreateMaintenance;
use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\Maintenance;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CreateMaintenanceParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidPayloads(): array
    {
        $once = ['title' => 'M', 'strategy' => 'once', 'starts_at' => '2030-01-01T00:00:00Z', 'ends_at' => '2030-01-01T01:00:00Z'];

        return [
            'empty' => [[]],
            'no monitors' => [$once],
            'unknown strategy' => [['title' => 'M', 'strategy' => 'lunar', 'monitor_ids' => [1]]],
            'foreign monitor' => [$once + ['monitor_ids' => [999999]]],
            'end before start' => [['ends_at' => '2029-01-01T00:00:00Z'] + $once + ['monitor_ids' => [999999]]],
            'bad cron' => [['title' => 'M', 'strategy' => 'cron', 'cron' => 'nope', 'duration_minutes' => 30, 'monitor_ids' => [999999]]],
            'weekly without time' => [['title' => 'M', 'strategy' => 'weekly', 'weekdays' => [1], 'duration_minutes' => 30, 'monitor_ids' => [999999]]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidPayloads')]
    public function test_create_action_matches_api_validation(array $payload): void
    {
        $ws = Workspace::factory()->create();
        $this->actingAs(User::factory()->memberOf($ws, WorkspaceRole::Editor)->create());

        $response = $this->postJson("/api/v1/workspaces/{$ws->slug}/maintenances", $payload);
        $response->assertStatus(422);

        try {
            app(CreateMaintenance::class)->validate($ws->refresh(), $payload);
            $this->fail('Validation should have failed.');
        } catch (ValidationException $e) {
            $apiErrors = $response->json('errors');
            $this->assertIsArray($apiErrors);
            $this->assertSame(array_keys($apiErrors), array_keys($e->errors()));
            $this->assertEquals($apiErrors, $e->errors());
        }
    }

    public function test_valid_payload_round_trips_through_handle(): void
    {
        $ws = Workspace::factory()->create()->refresh();
        $monitor = Monitor::factory()->create(['workspace_id' => $ws->id]);
        $page = StatusPage::factory()->create(['workspace_id' => $ws->id]);
        $action = app(CreateMaintenance::class);

        $validated = $action->validate($ws, [
            'title' => 'Patching', 'strategy' => 'weekly', 'weekdays' => [7, 1], 'start_time' => '02:00', 'duration_minutes' => 60,
            'timezone' => 'Europe/Budapest', 'monitor_ids' => [$monitor->id], 'status_page_ids' => [$page->id],
        ]);
        $joined = null;
        $maintenance = $action->handle($ws, $validated, null, function (Maintenance $m) use (&$joined): void {
            $joined = $m->id;
        });

        $this->assertSame($maintenance->id, $joined);
        $this->assertSame([1, 7], $maintenance->weekdays);
        $this->assertSame([$monitor->id], $maintenance->monitors()->pluck('monitors.id')->all());
        $this->assertSame(1, DB::table('status_page_links')->where('linkable_type', 'maintenance')->where('linkable_id', $maintenance->id)->count());
        $this->assertSame('system', AuditEvent::where('action', 'maintenance.created')->sole()->actor_type);
    }

    public function test_within_failure_rolls_the_maintenance_back(): void
    {
        $ws = Workspace::factory()->create()->refresh();
        $monitor = Monitor::factory()->create(['workspace_id' => $ws->id]);
        $action = app(CreateMaintenance::class);
        $validated = $action->validate($ws, ['title' => 'M', 'strategy' => 'once', 'starts_at' => '2030-01-01T00:00:00Z', 'ends_at' => '2030-01-01T01:00:00Z', 'monitor_ids' => [$monitor->id]]);

        try {
            $action->handle($ws, $validated, null, function (): void {
                throw new \RuntimeException('stop');
            });
            $this->fail('The callback exception should propagate.');
        } catch (\RuntimeException) {
            $this->assertSame(0, Maintenance::count());
            $this->assertSame(0, DB::table('maintenance_monitor')->count());
        }
    }

    public function test_targets_can_be_left_unchecked_for_entities_that_do_not_exist_yet(): void
    {
        $ws = Workspace::factory()->create()->refresh();
        $payload = ['title' => 'M', 'strategy' => 'once', 'starts_at' => '2030-01-01T00:00:00Z', 'ends_at' => '2030-01-01T01:00:00Z', 'monitor_ids' => [-1]];

        $validated = app(CreateMaintenance::class)->validate($ws, $payload, checkTargets: false);

        $this->assertSame([-1], $validated['monitor_ids']);
        $this->expectException(ValidationException::class);
        app(CreateMaintenance::class)->validate($ws, $payload);
    }
}
