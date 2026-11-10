<?php

namespace Tests\Feature;

use App\Enums\MonitorStatus;
use App\Models\CheckResult;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\User;
use Database\Seeders\ManualScreenshotSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class ManualScreenshotSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            // Directly, not through db:seed, which would first ask for confirmation in production.
            $this->app->make(ManualScreenshotSeeder::class)->setContainer($this->app)->__invoke();
            $this->fail('The seeder ran in production.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('never runs in production', $e->getMessage());
        }
        $this->assertSame(0, User::query()->count());
    }

    public function test_it_seeds_the_demo_workspace_relative_to_the_pinned_time(): void
    {
        $now = 1_794_300_000;
        config(['intrawatch.manual_screenshots' => ['locale' => 'en', 'password' => 'secret-pass', 'now' => (string) $now]]);

        $this->seed(ManualScreenshotSeeder::class);

        $user = User::query()->sole();
        $this->assertSame(ManualScreenshotSeeder::EMAIL, $user->email);
        $this->assertTrue(Hash::check('secret-pass', $user->password));
        $this->assertSame(1, $user->workspaces()->count());

        $statuses = Monitor::query()->with('state')->get()->mapWithKeys(fn (Monitor $m): array => [$m->name => $m->state->status]);
        $this->assertCount(8, $statuses);
        $this->assertSame(MonitorStatus::Degraded, $statuses['Billing']);
        $this->assertSame(MonitorStatus::Down, $statuses['Mail server']);
        $this->assertSame(6, $statuses->filter(fn (MonitorStatus $s): bool => $s === MonitorStatus::Up)->count());

        $newest = CheckResult::query()->max('checked_at');
        $this->assertIsString($newest);
        $this->assertSame($now - 20, strtotime($newest.' UTC'));

        $incident = Incident::query()->sole();
        $this->assertSame('Email delivery delayed', $incident->title);
        $this->assertSame(2, $incident->updates()->count());
        $this->assertSame(1, Maintenance::query()->count());
        $this->assertSame(5, StatusPage::query()->sole()->items()->count());
    }
}
