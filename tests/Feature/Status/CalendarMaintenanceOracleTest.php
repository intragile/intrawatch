<?php

namespace Tests\Feature\Status;

use App\Enums\MonitorStatus;
use App\Models\CheckResult;
use App\Models\Maintenance;
use App\Models\Monitor;
use App\Models\Workspace;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\MaintenanceOracle;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Engine\NullGroupEvaluator;
use App\Monitoring\Engine\ResultRecorder;
use App\Monitoring\Engine\StatusMachine;
use App\Status\Maintenance\CalendarMaintenanceOracle;
use App\Status\Maintenance\MaintenanceCalendarLoader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeClock;
use Tests\TestCase;

class CalendarMaintenanceOracleTest extends TestCase
{
    use RefreshDatabase;

    private FakeClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FakeClock(CarbonImmutable::parse('2027-03-01 09:00:00', 'UTC'));
    }

    private function oracle(): CalendarMaintenanceOracle
    {
        return new CalendarMaintenanceOracle(MaintenanceCalendarLoader::fromConfig(), $this->clock, 5, 60);
    }

    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse("2027-03-01 {$time}", 'UTC');
    }

    /**
     * @param  list<int>  $monitorIds
     */
    private function maintenance(string $start, ?string $end, array $monitorIds, ?Workspace $workspace = null): Maintenance
    {
        $maintenance = Maintenance::factory()->create([
            'workspace_id' => ($workspace ?? Workspace::factory()->create())->id,
            'starts_at' => $this->at($start),
            'ends_at' => $end === null ? null : $this->at($end),
        ]);
        $maintenance->monitors()->attach($monitorIds);

        return $maintenance;
    }

    public function test_overlapping_windows_merge(): void
    {
        $monitor = Monitor::factory()->create();
        $this->maintenance('10:00', '11:00', [$monitor->id]);
        $this->maintenance('10:30', '12:00', [$monitor->id]);
        $this->maintenance('12:00', '12:30', [$monitor->id]);

        $calendar = (new MaintenanceCalendarLoader)->load($this->at('09:00'));

        $this->assertSame([$monitor->id], $calendar->underMaintenance([$monitor->id], $this->at('11:15')));
        $this->assertSame([$monitor->id], $calendar->underMaintenance([$monitor->id], $this->at('11:59:59')));
        $this->assertSame([$monitor->id], $calendar->underMaintenance([$monitor->id], $this->at('12:00')));
        $this->assertSame([], $calendar->underMaintenance([$monitor->id], $this->at('12:30')));
        $this->assertSame($this->at('12:30')->getTimestamp(), $calendar->nextBoundaryAfter($this->at('10:45'))?->getTimestamp());
    }

    public function test_open_ended_window_stays_open_when_merged(): void
    {
        $monitor = Monitor::factory()->create();
        $this->maintenance('10:00', '11:00', [$monitor->id]);
        $this->maintenance('10:30', null, [$monitor->id]);

        $calendar = (new MaintenanceCalendarLoader)->load($this->at('09:00'));

        $this->assertSame([$monitor->id], $calendar->underMaintenance([$monitor->id], $this->at('11:00')->addHours(30)));
    }

    public function test_group_target_covers_descendants(): void
    {
        $workspace = Workspace::factory()->create();
        $g = Monitor::factory()->group()->create(['workspace_id' => $workspace->id]);
        $h = Monitor::factory()->group()->create(['workspace_id' => $workspace->id, 'parent_id' => $g->id]);
        $leaf = Monitor::factory()->create(['workspace_id' => $workspace->id, 'parent_id' => $h->id]);
        $sibling = Monitor::factory()->create(['workspace_id' => $workspace->id]);
        $this->maintenance('10:00', '11:00', [$g->id], $workspace);

        $calendar = (new MaintenanceCalendarLoader)->load($this->at('09:00'));

        $covered = $calendar->underMaintenance([$g->id, $h->id, $leaf->id, $sibling->id], $this->at('10:30'));
        $this->assertEqualsCanonicalizing([$g->id, $h->id, $leaf->id], $covered);
    }

    public function test_inactive_maintenance_and_workspace_timezone(): void
    {
        $monitor = Monitor::factory()->create();
        $inactive = $this->maintenance('10:00', '11:00', [$monitor->id]);
        $inactive->update(['active' => false]);

        $this->assertSame([], (new MaintenanceCalendarLoader)->load($this->at('09:00'))->underMaintenance([$monitor->id], $this->at('10:30')));
    }

    public function test_under_maintenance_does_not_query_after_warm(): void
    {
        $monitor = Monitor::factory()->create();
        $this->maintenance('08:00', '12:00', [$monitor->id]);
        $oracle = $this->oracle();
        $oracle->warm();

        DB::enableQueryLog();
        $result = $oracle->underMaintenance([...range(1, 2000), $monitor->id], $this->at('09:00'));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $queries);
        $this->assertSame([$monitor->id], array_values(array_unique($result)));
    }

    public function test_warm_is_throttled_and_reloads_on_change(): void
    {
        $monitor = Monitor::factory()->create();
        $oracle = $this->oracle();
        $oracle->warm();
        $this->assertSame([], $oracle->underMaintenance([$monitor->id], $this->at('09:00')));

        $this->maintenance('08:00', '12:00', [$monitor->id]);

        DB::enableQueryLog();
        $oracle->warm();
        $this->assertSame([], DB::getQueryLog(), 'within the stamp interval nothing is read');
        DB::disableQueryLog();
        $this->assertSame([], $oracle->underMaintenance([$monitor->id], $this->at('09:00')));

        $this->clock->advance(6);
        $oracle->warm();
        $this->assertSame([$monitor->id], $oracle->underMaintenance([$monitor->id], $this->clock->now()));
    }

    public function test_warm_reloads_when_the_calendar_is_old(): void
    {
        $monitor = Monitor::factory()->create();
        $maintenance = $this->maintenance('08:00', '12:00', []);
        $oracle = $this->oracle();
        $oracle->warm();
        // A change the stamp cannot see: a pivot row without touching the maintenance.
        $this->clock->advance(6);
        $maintenance->monitors()->attach($monitor->id);
        $oracle->warm();
        $this->assertSame([], $oracle->underMaintenance([$monitor->id], $this->clock->now()));

        $this->clock->advance(60);
        $oracle->warm();
        $this->assertSame([$monitor->id], $oracle->underMaintenance([$monitor->id], $this->clock->now()));
    }

    public function test_warm_keeps_the_calendar_on_db_error_and_logs_once_a_minute(): void
    {
        $monitor = Monitor::factory()->create();
        $this->maintenance('08:00', '12:00', [$monitor->id]);
        $loader = new class extends MaintenanceCalendarLoader
        {
            public bool $fail = false;

            public function stamp(): string
            {
                if ($this->fail) {
                    throw new \RuntimeException('database gone');
                }

                return parent::stamp();
            }
        };
        $oracle = new CalendarMaintenanceOracle($loader, $this->clock, 5, 60);
        $oracle->warm();

        $warnings = 0;
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$warnings): void {
            if ($m->level === 'warning') {
                $warnings++;
            }
        });
        $loader->fail = true;
        for ($i = 0; $i < 5; $i++) {
            $this->clock->advance(6);
            $oracle->warm();
        }

        $this->assertSame(1, $warnings);
        $this->assertSame([$monitor->id], $oracle->underMaintenance([$monitor->id], $this->clock->now()));

        $this->clock->advance(60);
        $oracle->warm();
        $this->assertSame(2, $warnings);
    }

    public function test_first_use_warms_lazily_and_outside_horizon_answers_nothing(): void
    {
        $monitor = Monitor::factory()->create();
        $this->maintenance('08:00', '12:00', [$monitor->id]);
        $oracle = $this->oracle();

        $this->assertSame([$monitor->id], $oracle->underMaintenance([$monitor->id], $this->at('09:00')));
        $this->assertSame([], $oracle->underMaintenance([$monitor->id], $this->at('09:00')->subDays(3)));
    }

    public function test_recorder_warms_outside_the_transaction(): void
    {
        $monitor = Monitor::factory()->create();
        $baseline = DB::transactionLevel();
        $spy = new class implements MaintenanceOracle
        {
            public ?int $level = null;

            public function underMaintenance(array $monitorIds, CarbonImmutable $at): array
            {
                return [];
            }

            public function warm(): void
            {
                $this->level = DB::transactionLevel();
            }
        };

        (new ResultRecorder(new StatusMachine, $spy, new NullGroupEvaluator))
            ->record([[MonitorSpec::fromModel($monitor), CheckOutcome::up(10), CarbonImmutable::now(), null]]);

        $this->assertSame($baseline, $spy->level);
    }

    public function test_check_inside_window_records_maintenance(): void
    {
        $monitor = Monitor::factory()->create();
        $this->maintenance('08:00', '12:00', [$monitor->id]);
        $this->app->instance(MaintenanceOracle::class, $this->oracle());

        $this->app->make(ResultRecorder::class)->record([
            [MonitorSpec::fromModel($monitor), CheckOutcome::down('refused'), $this->at('09:00'), null],
            [MonitorSpec::fromModel($monitor), CheckOutcome::down('refused'), $this->at('12:00'), null],
        ]);

        $rows = CheckResult::where('monitor_id', $monitor->id)->orderBy('id')->get();
        $this->assertSame(MonitorStatus::Maintenance, $rows->first()?->status);
        $this->assertNotSame(MonitorStatus::Maintenance, $rows->last()?->status);
    }
}
