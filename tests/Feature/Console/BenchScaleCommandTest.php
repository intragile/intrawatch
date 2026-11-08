<?php

namespace Tests\Feature\Console;

use App\Console\Commands\BenchScaleCommand;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class BenchScaleCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SMALL = ['--monitors' => 40, '--pages' => 3, '--items' => 4, '--requests' => 30, '--settle' => 0, '--base-url' => 'http://bench.test:3000'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function fakeApplication(): void
    {
        Http::fake(['bench.test:3000/*' => Http::response(['data' => [], 'meta' => ['next_cursor' => null, 'limit' => 100]])]);
    }

    public function test_it_seeds_measures_over_http_and_cleans_up(): void
    {
        $this->fakeApplication();
        $exit = Artisan::call('intrawatch:bench:scale', self::SMALL);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('p95', $output);
        $summary = $this->summary($output);
        $this->assertTrue($summary['passed'] ?? null);
        $seeded = $summary['seeded'] ?? null;
        $this->assertIsArray($seeded);
        $this->assertSame(40, $seeded['monitors'] ?? null);
        $this->assertSame(3, $seeded['pages'] ?? null);
        $admin = $summary['admin'] ?? null;
        $this->assertIsArray($admin);
        $this->assertCount(10, $admin);
        $this->assertArrayHasKey('monitors top level', $admin);
        $status = $summary['status'] ?? null;
        $this->assertIsArray($status);
        $this->assertSame(['cold', 'warm'], array_keys($status));

        // Admin lists go through the API with a bearer token; status pages from many client addresses.
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/api/v1/workspaces/bench-scale/monitors?parent_id=null&limit=100')
            && collect($r->header('Authorization'))->contains(static fn (mixed $v): bool => is_string($v) && str_starts_with($v, 'Bearer ')));
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/api/v1/workspaces/bench-scale/audit'));
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/status/') && $r->hasHeader('X-Forwarded-For'));
        $statusRequests = Http::recorded(static fn (Request $r): bool => str_contains($r->url(), '/status/'));
        $this->assertCount(3 + 30, $statusRequests);
        foreach ($statusRequests as [$request]) {
            $this->assertCount(1, $request->header('X-Forwarded-For'));
        }

        $this->assertSame(0, Workspace::query()->count());
        $this->assertSame(0, User::query()->count());
        foreach (['monitors', 'status_pages', 'check_rollups', 'tags', 'notification_channels', 'personal_access_tokens', 'audit_events', 'alert_events', 'incidents', 'maintenances'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_keep_leaves_the_seeded_workspace(): void
    {
        $this->fakeApplication();
        $this->assertSame(0, Artisan::call('intrawatch:bench:scale', [...self::SMALL, '--keep' => true]), Artisan::output());

        $workspace = Workspace::query()->sole();
        $this->assertSame('bench-scale', $workspace->slug);
        $leaves = DB::table('monitors')->where('workspace_id', $workspace->id)->where('type', '!=', 'group');
        $this->assertSame(40, $leaves->count());
        $this->assertSame(24, (clone $leaves)->where('type', 'http')->count());
        $this->assertSame(40, (clone $leaves)->where('active', true)->count());
        $this->assertGreaterThan(0, DB::table('monitors')->where('workspace_id', $workspace->id)->where('type', 'group')->whereNotNull('parent_id')->count());
        $this->assertSame(3, DB::table('status_pages')->where('workspace_id', $workspace->id)->count());
        $this->assertSame(12, DB::table('status_page_items')->count());
        $this->assertSame(15, DB::table('notification_channels')->where('active', false)->count());
        $this->assertSame(40 * (90 + 48), DB::table('check_rollups')->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_refuses_a_populated_database_unless_forced(): void
    {
        $this->fakeApplication();
        Workspace::factory()->create();

        $this->assertSame(1, Artisan::call('intrawatch:bench:scale', self::SMALL));
        $this->assertSame(1, Workspace::query()->count());
        Http::assertNothingSent();

        $this->assertSame(0, Artisan::call('intrawatch:bench:scale', [...self::SMALL, '--force' => true]), Artisan::output());
        $this->assertSame(1, Workspace::query()->count());
    }

    public function test_a_failing_request_fails_the_run(): void
    {
        Http::fake(['bench.test:3000/status/*' => Http::response('', 500), 'bench.test:3000/*' => Http::response(['data' => []])]);

        $this->assertSame(1, Artisan::call('intrawatch:bench:scale', self::SMALL));
        $this->assertFalse($this->summary(Artisan::output())['passed'] ?? null);
        $this->assertSame(0, Workspace::query()->count());
    }

    public function test_status_429_hints_at_trusted_proxies(): void
    {
        Http::fake(['bench.test:3000/status/*' => Http::response('', 429), 'bench.test:3000/*' => Http::response(['data' => []])]);

        $this->assertSame(1, Artisan::call('intrawatch:bench:scale', self::SMALL));
        $output = Artisan::output();
        $this->assertStringContainsString('Is TRUSTED_PROXIES=127.0.0.1 set on the app?', $output);
        $this->assertStringContainsString('never in production', $output);
    }

    public function test_a_failed_clean_up_fails_the_run(): void
    {
        $this->fakeApplication();
        Event::listen(QueryExecuted::class, static function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'delete') && str_contains($query->sql, 'alert_events')) {
                throw new RuntimeException('simulated clean-up failure');
            }
        });

        $this->assertSame(1, Artisan::call('intrawatch:bench:scale', self::SMALL));
        $output = Artisan::output();
        $this->assertStringContainsString('Could not delete workspace bench-scale: simulated clean-up failure', $output);
        $this->assertTrue($this->summary($output)['passed'] ?? null);
    }

    public function test_rejects_invalid_options(): void
    {
        $this->assertSame(2, Artisan::call('intrawatch:bench:scale', [...self::SMALL, '--monitors' => 0]));
        $this->assertSame(2, Artisan::call('intrawatch:bench:scale', [...self::SMALL, '--items' => 251]));
        $this->assertSame(2, Artisan::call('intrawatch:bench:scale', [...self::SMALL, '--base-url' => 'not a url']));
    }

    public function test_verdict(): void
    {
        $this->assertSame([], BenchScaleCommand::verdict(['a' => 499.9], 99.9, 0));
        $this->assertCount(1, BenchScaleCommand::verdict(['a' => 500.0], 1.0, 0));
        $this->assertCount(1, BenchScaleCommand::verdict(['a' => 1.0], 100.0, 0));
        $this->assertCount(1, BenchScaleCommand::verdict(['a' => 1.0], 1.0, 3));
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(string $output): array
    {
        $lines = array_values(array_filter(explode("\n", trim($output)), static fn (string $l): bool => str_starts_with($l, '{')));
        $this->assertNotEmpty($lines, $output);
        $decoded = json_decode($lines[count($lines) - 1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
