<?php

namespace Tests\Feature\Api;

use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\Maintenance;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\Workspace;
use App\Monitoring\Engine\MaintenanceOracle;
use App\Monitoring\Worker\Clock;
use App\Status\Maintenance\CalendarMaintenanceOracle;
use App\Status\Maintenance\MaintenanceCalendar;
use App\Status\Maintenance\MaintenanceCalendarLoader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsWorkspaceIsolation;
use Tests\TestCase;

class MaintenanceApiTest extends TestCase
{
    use AssertsWorkspaceIsolation;
    use RefreshDatabase;

    private Workspace $ws;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-06 10:00:00', 'UTC'));
        $this->ws = Workspace::factory()->create(['timezone' => 'Europe/Budapest']);
        $this->editor = User::factory()->memberOf($this->ws, WorkspaceRole::Editor)->create();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/workspaces/{$this->ws->slug}/maintenances{$path}";
    }

    private function monitor(?Workspace $ws = null): Monitor
    {
        return Monitor::factory()->for($ws ?? $this->ws)->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function oneOff(Monitor $monitor, string $from = '2026-11-06T11:00:00Z', ?string $to = '2026-11-06T12:00:00Z'): array
    {
        return ['title' => 'DB upgrade', 'strategy' => 'once', 'starts_at' => $from, 'ends_at' => $to, 'monitor_ids' => [$monitor->id]];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function windowOn(Monitor $monitor, string $title, array $attributes = []): void
    {
        $maintenance = Maintenance::factory()->state($attributes)->create(['workspace_id' => $this->ws->id, 'title' => $title]);
        $maintenance->monitors()->attach($monitor->id);
    }

    private function id(mixed $value): int
    {
        $this->assertIsInt($value);

        return $value;
    }

    public function test_crud_validation_and_audit(): void
    {
        $monitor = $this->monitor();
        $page = StatusPage::factory()->create(['workspace_id' => $this->ws->id]);

        $response = $this->actingAs($this->editor)->postJson($this->url(), $this->oneOff($monitor) + ['description' => 'Planned', 'status_page_ids' => [$page->id]])
            ->assertCreated()
            ->assertJsonPath('data.title', 'DB upgrade')
            ->assertJsonPath('data.strategy', 'once')
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.monitor_ids', [$monitor->id])
            ->assertJsonPath('data.status_page_ids', [$page->id])
            ->assertJsonPath('data.in_progress', false)
            ->assertJsonStructure(['data' => ['id', 'title', 'description', 'strategy', 'timezone', 'starts_at', 'ends_at', 'start_time', 'duration_minutes', 'every_days', 'weekdays', 'month_days', 'cron', 'valid_from', 'valid_until', 'active', 'monitor_ids', 'status_page_ids', 'in_progress', 'next_window', 'created_at', 'updated_at']]);
        $id = $this->id($response->json('data.id'));

        $this->actingAs($this->editor)->getJson($this->url("/{$id}"))->assertOk()->assertJsonPath('data.id', $id);
        $this->actingAs($this->editor)->getJson($this->url())->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonStructure(['meta' => ['next_cursor', 'limit']]);

        // Fields of other strategies are nulled; sending monitor_ids again with a new set replaces the targets.
        $other = $this->monitor();
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), [
            'strategy' => 'weekly', 'weekdays' => [1, 3], 'start_time' => '02:30', 'duration_minutes' => 60, 'monitor_ids' => [$other->id],
        ])->assertOk()
            ->assertJsonPath('data.strategy', 'weekly')
            ->assertJsonPath('data.starts_at', null)
            ->assertJsonPath('data.ends_at', null)
            ->assertJsonPath('data.weekdays', [1, 3])
            ->assertJsonPath('data.monitor_ids', [$other->id])
            ->assertJsonPath('data.status_page_ids', [$page->id]);

        $this->actingAs($this->editor)->deleteJson($this->url("/{$id}"))->assertNoContent();
        $this->assertDatabaseMissing('maintenances', ['id' => $id]);
        $this->assertDatabaseMissing('maintenance_monitor', ['maintenance_id' => $id]);
        $this->assertDatabaseMissing('status_page_links', ['linkable_type' => 'maintenance', 'linkable_id' => $id]);

        $actions = AuditEvent::query()->where('action', 'like', 'maintenance.%')->orderBy('id')->pluck('action')->all();
        $this->assertSame(['maintenance.created', 'maintenance.updated', 'maintenance.deleted'], $actions);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function unusableDefinitions(): array
    {
        return [
            'weekly without start time' => [['strategy' => 'weekly', 'weekdays' => [1], 'duration_minutes' => 30], 'start_time'],
            'interval without duration' => [['strategy' => 'interval', 'every_days' => 2, 'start_time' => '01:00'], 'duration_minutes'],
            'monthly without duration' => [['strategy' => 'monthly', 'month_days' => [1], 'start_time' => '01:00'], 'duration_minutes'],
            'cron without duration' => [['strategy' => 'cron', 'cron' => '0 3 * * *'], 'duration_minutes'],
            'invalid cron' => [['strategy' => 'cron', 'cron' => 'nope', 'duration_minutes' => 30], 'cron'],
            'empty weekdays' => [['strategy' => 'weekly', 'weekdays' => [], 'start_time' => '01:00', 'duration_minutes' => 30], 'weekdays'],
            'empty month days' => [['strategy' => 'monthly', 'month_days' => [], 'start_time' => '01:00', 'duration_minutes' => 30], 'month_days'],
            'month day zero' => [['strategy' => 'monthly', 'month_days' => [0], 'start_time' => '01:00', 'duration_minutes' => 30], 'month_days.0'],
            'duration too long' => [['strategy' => 'weekly', 'weekdays' => [1], 'start_time' => '01:00', 'duration_minutes' => 10081], 'duration_minutes'],
            'once end before start' => [['strategy' => 'once', 'starts_at' => '2026-11-06T12:00:00Z', 'ends_at' => '2026-11-06T11:00:00Z'], 'ends_at'],
            'once end equals start' => [['strategy' => 'once', 'starts_at' => '2026-11-06T12:00:00Z', 'ends_at' => '2026-11-06T12:00:00Z'], 'ends_at'],
            'once without start' => [['strategy' => 'once'], 'starts_at'],
            'unknown timezone' => [['strategy' => 'once', 'starts_at' => '2026-11-06T12:00:00Z', 'timezone' => 'Mars/Base'], 'timezone'],
            'valid until before from' => [['strategy' => 'weekly', 'weekdays' => [1], 'start_time' => '01:00', 'duration_minutes' => 30, 'valid_from' => '2026-12-01', 'valid_until' => '2026-11-01'], 'valid_until'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('unusableDefinitions')]
    public function test_definitions_that_never_produce_a_window_are_rejected(array $body, string $field): void
    {
        $body += ['title' => 'x', 'monitor_ids' => [$this->monitor()->id]];

        $this->actingAs($this->editor)->postJson($this->url(), $body)
            ->assertUnprocessable()->assertJsonValidationErrors([$field]);
        $this->assertSame(0, Maintenance::query()->count());
    }

    public function test_an_update_cannot_turn_a_valid_row_into_an_unusable_one(): void
    {
        $monitor = $this->monitor();
        $id = $this->id($this->actingAs($this->editor)->postJson($this->url(), $this->oneOff($monitor))->assertCreated()->json('data.id'));

        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['strategy' => 'weekly'])
            ->assertUnprocessable()->assertJsonValidationErrors(['weekdays']);
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['ends_at' => '2026-11-06T10:30:00Z'])
            ->assertUnprocessable()->assertJsonValidationErrors(['ends_at']);
        $this->assertSame('once', Maintenance::query()->findOrFail($id)->strategy->value);
    }

    public function test_an_update_may_omit_the_title_and_strategy_but_not_clear_them(): void
    {
        $monitor = $this->monitor();
        $id = $this->id($this->actingAs($this->editor)->postJson($this->url(), $this->oneOff($monitor))->assertCreated()->json('data.id'));

        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['description' => 'Rescheduled'])
            ->assertOk()->assertJsonPath('data.title', 'DB upgrade')->assertJsonPath('data.strategy', 'once');
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['title' => null, 'strategy' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title' => 'The title field is required.', 'strategy' => 'The strategy field is required.']);
        $this->assertSame('DB upgrade', Maintenance::query()->findOrFail($id)->title);
    }

    public function test_cross_workspace_ids_are_rejected(): void
    {
        [$actor, $own, $foreign] = $this->crossWorkspaceFixture();
        $foreignMonitor = Monitor::factory()->for($foreign)->create();
        $ownMonitor = Monitor::factory()->for($own)->create();
        $foreignPage = StatusPage::factory()->create(['workspace_id' => $foreign->id]);
        $base = "/api/v1/workspaces/{$own->slug}/maintenances";
        $body = ['title' => 'x', 'strategy' => 'once', 'starts_at' => '2026-11-06T11:00:00Z'];

        $this->actingAs($actor)->postJson($base, $body + ['monitor_ids' => [$foreignMonitor->id]])
            ->assertUnprocessable()->assertJsonValidationErrors(['monitor_ids']);
        $this->actingAs($actor)->postJson($base, $body + ['monitor_ids' => [$ownMonitor->id, $foreignMonitor->id]])
            ->assertUnprocessable()->assertJsonValidationErrors(['monitor_ids']);
        $this->actingAs($actor)->postJson($base, $body + ['monitor_ids' => [$ownMonitor->id], 'status_page_ids' => [$foreignPage->id]])
            ->assertUnprocessable()->assertJsonValidationErrors(['status_page_ids']);
        $this->assertSame(0, Maintenance::query()->count());

        $maintenance = Maintenance::factory()->create(['workspace_id' => $foreign->id]);
        $url = "{$base}/{$maintenance->id}";
        $this->assertCrossWorkspaceNotFound($actor, 'GET', $url);
        $this->assertCrossWorkspaceNotFound($actor, 'PATCH', $url, ['title' => 'x']);
        $this->assertCrossWorkspaceNotFound($actor, 'DELETE', $url);
        $this->assertCrossWorkspaceNotFound($actor, 'POST', $url.'/end');
        $this->assertDatabaseHas('maintenances', ['id' => $maintenance->id]);

        // The foreign workspace's own list never shows it to this actor either.
        $this->actingAs($actor)->getJson($base)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_viewer_reads_but_cannot_write(): void
    {
        $viewer = User::factory()->memberOf($this->ws, WorkspaceRole::Viewer)->create();
        $maintenance = Maintenance::factory()->create(['workspace_id' => $this->ws->id]);

        $this->actingAs($viewer)->getJson($this->url())->assertOk();
        $this->actingAs($viewer)->getJson($this->url("/{$maintenance->id}"))->assertOk();
        $this->actingAs($viewer)->postJson($this->url(), $this->oneOff($this->monitor()))->assertForbidden();
        $this->actingAs($viewer)->deleteJson($this->url("/{$maintenance->id}"))->assertForbidden();
        $this->actingAs($viewer)->postJson($this->url("/{$maintenance->id}/end"))->assertForbidden();
    }

    public function test_end_closes_an_open_ended_window(): void
    {
        $monitor = $this->monitor();
        $id = $this->id($this->actingAs($this->editor)->postJson($this->url(), $this->oneOff($monitor, '2026-11-06T09:00:00Z', null))->assertCreated()
            ->assertJsonPath('data.in_progress', true)->assertJsonPath('data.next_window.ends_at', null)->json('data.id'));

        $this->actingAs($this->editor)->postJson($this->url("/{$id}/end"))->assertOk()
            ->assertJsonPath('data.in_progress', false)
            ->assertJsonPath('data.next_window', null)
            ->assertJsonPath('data.ends_at', '2026-11-06T10:00:00+00:00');
        $this->assertDatabaseHas('audit_events', ['action' => 'maintenance.ended']);

        // A window that already ended cannot be ended again.
        $this->actingAs($this->editor)->postJson($this->url("/{$id}/end"))->assertUnprocessable();

        // Recurring and not-yet-started windows are refused.
        $weekly = Maintenance::factory()->create(['workspace_id' => $this->ws->id, 'strategy' => 'weekly', 'weekdays' => [1], 'start_time' => '01:00', 'duration_minutes' => 30, 'starts_at' => null, 'ends_at' => null]);
        $this->actingAs($this->editor)->postJson($this->url("/{$weekly->id}/end"))->assertUnprocessable()->assertJsonValidationErrors(['strategy']);
        $future = Maintenance::factory()->create(['workspace_id' => $this->ws->id]);
        $this->actingAs($this->editor)->postJson($this->url("/{$future->id}/end"))->assertUnprocessable();
    }

    public function test_next_window_and_in_progress_in_resource(): void
    {
        $monitor = $this->monitor();
        // Weekly Friday 02:30 Budapest; now is Friday 2026-11-06 11:00 local, so the next one is a week away.
        $id = $this->id($this->actingAs($this->editor)->postJson($this->url(), [
            'title' => 'Weekly', 'strategy' => 'weekly', 'weekdays' => [5], 'start_time' => '02:30', 'duration_minutes' => 60,
            'monitor_ids' => [$monitor->id],
        ])->assertCreated()
            ->assertJsonPath('data.in_progress', false)
            ->assertJsonPath('data.next_window.starts_at', '2026-11-13T01:30:00+00:00')
            ->assertJsonPath('data.next_window.ends_at', '2026-11-13T02:30:00+00:00')
            ->json('data.id'));

        // Inside the window the current one is reported.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-13 02:00:00', 'UTC'));
        $this->actingAs($this->editor)->getJson($this->url("/{$id}"))->assertOk()
            ->assertJsonPath('data.in_progress', true)
            ->assertJsonPath('data.next_window.starts_at', '2026-11-13T01:30:00+00:00');
    }

    public function test_state_filter(): void
    {
        $monitor = $this->monitor();
        $this->windowOn($monitor, 'running', ['starts_at' => '2026-11-06 09:00:00', 'ends_at' => '2026-11-06 11:00:00']);
        $this->windowOn($monitor, 'open', ['starts_at' => '2026-11-06 09:00:00', 'ends_at' => null]);
        $this->windowOn($monitor, 'later', ['starts_at' => '2026-11-07 09:00:00', 'ends_at' => '2026-11-07 11:00:00']);
        $this->windowOn($monitor, 'over', ['starts_at' => '2026-11-05 09:00:00', 'ends_at' => '2026-11-05 11:00:00']);
        $this->windowOn($monitor, 'off', ['starts_at' => '2026-11-06 09:00:00', 'ends_at' => '2026-11-06 11:00:00', 'active' => false]);
        $this->windowOn($monitor, 'expired', ['strategy' => 'weekly', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'start_time' => '01:00', 'duration_minutes' => 30, 'starts_at' => null, 'ends_at' => null, 'valid_until' => '2026-10-01']);
        $this->windowOn($monitor, 'nightly', ['strategy' => 'weekly', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'start_time' => '03:00', 'duration_minutes' => 30, 'starts_at' => null, 'ends_at' => null]);

        $titles = function (string $state): array {
            $rows = $this->actingAs($this->editor)->getJson($this->url("?state={$state}"))->assertOk()->json('data');
            $this->assertIsArray($rows);
            $titles = array_map(static fn (mixed $row): mixed => is_array($row) ? $row['title'] : null, $rows);
            sort($titles);

            return $titles;
        };

        $this->assertSame(['open', 'running'], $titles('active'));
        $this->assertSame(['later', 'nightly'], $titles('upcoming'));
        $this->assertSame(['expired', 'off', 'over'], $titles('past'));
        $this->assertCount(7, $titles('all'));
        $this->assertCount(7, $titles('all'));
        $this->actingAs($this->editor)->getJson($this->url('?state=bogus'))->assertUnprocessable();

        // A narrowed list still paginates.
        $page = $this->actingAs($this->editor)->getJson($this->url('?state=past&limit=2'))->assertOk();
        $this->assertCount(2, (array) $page->json('data'));
        $next = $page->json('meta.next_cursor');
        $this->assertIsString($next);
        $this->assertCount(1, (array) $this->actingAs($this->editor)->getJson($this->url('?state=past&limit=2&cursor='.urlencode($next)))->assertOk()->json('data'));
    }

    public function test_target_change_alone_bumps_updated_at(): void
    {
        $a = $this->monitor();
        $b = $this->monitor();
        $id = $this->id($this->actingAs($this->editor)->postJson($this->url(), $this->oneOff($a))->assertCreated()->json('data.id'));
        DB::table('maintenances')->where('id', $id)->update(['updated_at' => '2026-11-01 00:00:00']);

        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['monitor_ids' => [$a->id, $b->id]])->assertOk();

        $this->assertSame('2026-11-06 10:00:00', Maintenance::query()->findOrFail($id)->getRawOriginal('updated_at'));
    }

    public function test_a_maintenance_with_more_than_1000_targets_is_rejected(): void
    {
        $this->actingAs($this->editor)->postJson($this->url(), ['title' => 'x', 'strategy' => 'once', 'starts_at' => '2026-11-06T11:00:00Z', 'monitor_ids' => range(1, 1001)])
            ->assertUnprocessable()->assertJsonValidationErrors(['monitor_ids']);
        $this->actingAs($this->editor)->postJson($this->url(), ['title' => 'x', 'strategy' => 'once', 'starts_at' => '2026-11-06T11:00:00Z', 'monitor_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['monitor_ids']);
    }

    public function test_resending_the_same_ids_in_another_order_changes_nothing(): void
    {
        $a = $this->monitor();
        $b = $this->monitor();
        $body = $this->oneOff($a);
        $id = $this->id($this->actingAs($this->editor)->postJson($this->url(), ['monitor_ids' => [$a->id, $b->id]] + $body)->assertCreated()->json('data.id'));
        $audit = AuditEvent::query()->count();
        $pivot = DB::table('maintenance_monitor')->where('maintenance_id', $id)->orderBy('monitor_id')->pluck('monitor_id')->all();

        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['monitor_ids' => [$b->id, $a->id], 'status_page_ids' => []])->assertOk()
            ->assertJsonPath('data.monitor_ids', [$a->id, $b->id]);

        $this->assertSame($audit, AuditEvent::query()->count(), 'no audit row for an unchanged target set');
        $this->assertSame($pivot, DB::table('maintenance_monitor')->where('maintenance_id', $id)->orderBy('monitor_id')->pluck('monitor_id')->all());
    }

    public function test_a_failing_calendar_reload_rolls_the_write_back(): void
    {
        $monitor = $this->monitor();
        $failing = new class extends MaintenanceCalendarLoader
        {
            public function load(CarbonImmutable $now): MaintenanceCalendar
            {
                throw new \RuntimeException('database unavailable');
            }
        };
        $this->app->instance(MaintenanceOracle::class, new CalendarMaintenanceOracle($failing, $this->app->make(Clock::class)));

        // The window is in progress, so the sync needs the reloaded calendar.
        $this->actingAs($this->editor)->postJson($this->url(), $this->oneOff($monitor, '2026-11-06T09:00:00Z', '2026-11-06T12:00:00Z'))
            ->assertStatus(500)->assertHeader('Content-Type', 'application/problem+json');

        $this->assertSame(0, Maintenance::query()->count());
        $this->assertSame(0, DB::table('maintenance_monitor')->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'maintenance.created')->count());
    }

    public function test_every_write_moves_the_calendar_stamp_even_within_one_second(): void
    {
        $a = $this->monitor();
        $b = $this->monitor();
        $loader = new MaintenanceCalendarLoader;
        $id = $this->id($this->actingAs($this->editor)->postJson($this->url(), $this->oneOff($a))->assertCreated()->json('data.id'));
        $stamps = [$loader->stamp()];

        // The clock is frozen: every write below lands in the same second as the create.
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['monitor_ids' => [$a->id, $b->id]])->assertOk();
        $stamps[] = $loader->stamp();
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['title' => 'Renamed'])->assertOk();
        $stamps[] = $loader->stamp();
        $this->actingAs($this->editor)->patchJson($this->url("/{$id}"), ['monitor_ids' => [$a->id]])->assertOk();
        $stamps[] = $loader->stamp();
        $this->actingAs($this->editor)->deleteJson($this->url("/{$id}"))->assertNoContent();
        $stamps[] = $loader->stamp();

        $this->assertCount(5, array_unique($stamps));
    }

    public function test_dates_outside_the_portable_timestamp_range_are_rejected(): void
    {
        $monitor = $this->monitor();
        foreach ([['2040-01-01T00:00:00Z', null, 'starts_at'], ['1969-12-31T00:00:00Z', null, 'starts_at'], ['2026-11-06T11:00:00Z', '2038-02-01T00:00:00Z', 'ends_at']] as [$from, $to, $field]) {
            $this->actingAs($this->editor)->postJson($this->url(), $this->oneOff($monitor, $from, $to))
                ->assertUnprocessable()->assertJsonValidationErrors([$field]);
        }
        $this->actingAs($this->editor)->postJson($this->url(), $this->oneOff($monitor, '2026-11-06T11:00:00Z', '2037-12-31T00:00:00Z'))->assertCreated();
    }
}
