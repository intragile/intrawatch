<?php

declare(strict_types=1);

namespace Tests\Feature\Import\Kuma;

use App\Actions\StatusPages\CreateStatusPage;
use App\Enums\IncidentStatus;
use App\Enums\WorkspaceRole;
use App\Import\Kuma\ImportOptions;
use App\Import\Kuma\ImportReport;
use App\Import\Kuma\KumaImporter;
use App\Import\Kuma\Source\KumaSource;
use App\Import\Kuma\Source\SourceFactory;
use App\Import\Kuma\Steps\ImportIncidents;
use App\Import\Kuma\Steps\ImportMaintenances;
use App\Import\Kuma\Steps\ImportStatusPages;
use App\Models\AuditEvent;
use App\Models\ImportMapping;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\Workspace;
use App\Status\Pages\PageStamps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use Tests\TestCase;

/**
 * @phpstan-import-type ReportShape from ImportReport
 * @phpstan-import-type EntryShape from ImportReport
 */
final class ImportStatusTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<KumaSource> */
    private array $sources = [];

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->sources as $source) {
            $source->close();
        }
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /**
     * A workspace as the import loads it, with the database defaults hydrated.
     */
    private function workspace(?string $timezone = null): Workspace
    {
        $workspace = Workspace::factory()->create($timezone === null ? [] : ['timezone' => $timezone]);

        return $workspace->refresh();
    }

    /**
     * @return ReportShape
     */
    private function import(string $path, Workspace $workspace, bool $dryRun = false, ?User $actor = null, ?KumaImporter $importer = null): array
    {
        $source = $this->sources[] = SourceFactory::open($path);

        return ($importer ?? app(KumaImporter::class))->run($source, $workspace, new ImportOptions(dryRun: $dryRun), $actor)->toArray();
    }

    /**
     * A copy of a fixture database with the given statements applied.
     *
     * @param  list<string>  $statements
     */
    private function patched(string $name, array $statements): string
    {
        $path = tempnam(sys_get_temp_dir(), 'iw-kuma-');
        $this->assertNotFalse($path);
        $this->files[] = $path;
        copy(ImporterTest::fixture($name), $path);
        $pdo = new PDO('sqlite:'.$path);
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }

        return $path;
    }

    /**
     * @param  ReportShape  $report
     * @return array<int, EntryShape>
     */
    private function entries(array $report, string $entity): array
    {
        $entries = [];
        foreach ($report['entries'] as $entry) {
            if ($entry['entity'] === $entity) {
                $entries[$entry['source_id']] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param  EntryShape  $entry
     * @return list<string>
     */
    private static function codes(array $entry): array
    {
        return array_map(fn (array $n): string => $n['code'], $entry['notes']);
    }

    /**
     * Source monitor id => imported monitor id.
     *
     * @param  ReportShape  $report
     * @return array<int, int>
     */
    private function monitorIds(array $report): array
    {
        $ids = [];
        foreach ($this->entries($report, 'monitor') as $sourceId => $entry) {
            if ($entry['target_id'] !== null) {
                $ids[$sourceId] = $entry['target_id'];
            }
        }

        return $ids;
    }

    public function test_fixture_page_is_imported_with_sections_items_and_domain(): void
    {
        $ws = $this->workspace();

        $report = $this->import(ImporterTest::fixture('kuma2.db'), $ws);

        $page = StatusPage::query()->forWorkspace($ws)->sole();
        $this->assertSame(['status_page' => ['created' => 1]], array_intersect_key($report['counts'], ['status_page' => 1]));
        $this->assertSame('public', $page->slug);
        $this->assertSame('Public Status', $page->title);
        $this->assertSame('desc', $page->description);
        $this->assertSame('footer **md**', $page->footer);
        $this->assertSame('body{}', $page->custom_css);
        $this->assertTrue($page->published);
        $this->assertSame('en', $page->locale);
        $this->assertNull($page->logo_url);
        $this->assertSame('status.example.com', $page->custom_domain);

        $ids = $this->monitorIds($report);
        $sections = DB::table('status_page_sections')->where('status_page_id', $page->id)->orderBy('position')->pluck('name', 'id')->all();
        $this->assertSame(['Web', 'Infra'], array_values($sections));
        $sectionIds = array_keys($sections);
        $items = fn (int $sectionId): array => DB::table('status_page_items')->where('status_page_section_id', $sectionId)->orderBy('position')->pluck('monitor_id')->map(fn ($id): int => (int) (is_numeric($id) ? $id : 0))->all();
        $this->assertSame([$ids[2], $ids[3]], $items($sectionIds[0]));
        $this->assertSame([$ids[7], $ids[6]], $items($sectionIds[1]));

        $entry = $this->entries($report, 'status_page')[1];
        $this->assertSame($page->id, $entry['target_id']);
        $this->assertSame(['logo_not_imported', 'settings_ignored', 'item_urls_ignored'], self::codes($entry));
        $this->assertSame(1, ImportMapping::query()->forWorkspace($ws)->where('entity', 'status_page')->count());
        $this->assertSame(1, AuditEvent::where('action', 'status_page.created')->count());
        $this->assertSame(1, AuditEvent::where('action', 'status_page.layout_replaced')->count());
    }

    public function test_actor_without_domain_permission_gets_a_note_instead_of_the_domain(): void
    {
        config(['status.custom_domains.allowed' => ['status.example.com']]);
        $ws = $this->workspace();
        $editor = User::factory()->memberOf($ws, WorkspaceRole::Editor)->create();

        $report = $this->import(ImporterTest::fixture('kuma2.db'), $ws, actor: $editor);

        $page = StatusPage::query()->forWorkspace($ws)->sole();
        $this->assertNull($page->custom_domain);
        $this->assertContains('custom_domain_not_permitted', self::codes($this->entries($report, 'status_page')[1]));
    }

    public function test_admin_actor_may_set_the_domain(): void
    {
        config(['status.custom_domains.allowed' => ['status.example.com']]);
        $ws = $this->workspace();
        $admin = User::factory()->memberOf($ws, WorkspaceRole::Admin)->create();

        $this->import(ImporterTest::fixture('kuma2.db'), $ws, actor: $admin);

        $this->assertSame('status.example.com', StatusPage::query()->forWorkspace($ws)->sole()->custom_domain);
        $this->assertSame($admin->id, AuditEvent::where('action', 'status_page.created')->sole()->actor_user_id);
    }

    public function test_admin_actor_cannot_import_a_domain_outside_the_allowlist(): void
    {
        $ws = $this->workspace();
        $admin = User::factory()->memberOf($ws, WorkspaceRole::Admin)->create();

        foreach ([[], ['*.other.test'], ['example.com']] as $allowed) {
            config(['status.custom_domains.allowed' => $allowed]);
            StatusPage::query()->delete();
            ImportMapping::query()->delete();

            $report = $this->import(ImporterTest::fixture('kuma2.db'), $ws, actor: $admin);

            $page = StatusPage::query()->forWorkspace($ws)->sole();
            $this->assertNull($page->custom_domain);
            $codes = self::codes($this->entries($report, 'status_page')[1]);
            $this->assertContains('custom_domain_not_allowed', $codes);
            $this->assertNotContains('custom_domain_not_permitted', $codes);
        }

        config(['status.custom_domains.allowed' => ['*.example.com']]);
        StatusPage::query()->delete();
        ImportMapping::query()->delete();
        $this->import(ImporterTest::fixture('kuma2.db'), $ws, actor: $admin);
        $this->assertSame('status.example.com', StatusPage::query()->forWorkspace($ws)->sole()->custom_domain);
    }

    public function test_command_line_import_keeps_the_domain_with_an_empty_allowlist(): void
    {
        config(['status.custom_domains.allowed' => []]);
        $ws = $this->workspace();

        $report = $this->import(ImporterTest::fixture('kuma2.db'), $ws);

        $this->assertSame('status.example.com', StatusPage::query()->forWorkspace($ws)->sole()->custom_domain);
        $this->assertNotContains('custom_domain_not_allowed', self::codes($this->entries($report, 'status_page')[1]));
    }

    public function test_rejected_domain_is_noted_and_the_page_is_still_imported(): void
    {
        StatusPage::factory()->create(['slug' => 'other', 'custom_domain' => 'status.example.com']);
        $ws = $this->workspace();

        $report = $this->import(ImporterTest::fixture('kuma2.db'), $ws);

        $page = StatusPage::query()->forWorkspace($ws)->sole();
        $this->assertNull($page->custom_domain);
        $this->assertContains('custom_domain_rejected', self::codes($this->entries($report, 'status_page')[1]));
    }

    public function test_extra_domains_are_noted(): void
    {
        $path = $this->patched('kuma2.db', ["insert into status_page_cname (status_page_id, domain) values (1, 'second.example.com')"]);

        $report = $this->import($path, $this->workspace());

        $this->assertContains('extra_domains_ignored', self::codes($this->entries($report, 'status_page')[1]));
    }

    public function test_slug_collision_gets_suffix(): void
    {
        StatusPage::factory()->create(['slug' => 'public']);
        $ws = $this->workspace();

        $report = $this->import(ImporterTest::fixture('kuma2.db'), $ws);

        $this->assertSame('public-2', StatusPage::query()->forWorkspace($ws)->sole()->slug);
        $this->assertContains('slug_renamed', self::codes($this->entries($report, 'status_page')[1]));
    }

    public function test_items_reference_only_imported_monitors(): void
    {
        $ws = $this->workspace();
        $importer = new KumaImporter([ImportStatusPages::class]);

        $report = $this->import(ImporterTest::fixture('kuma2.db'), $ws, importer: $importer);

        $page = StatusPage::query()->forWorkspace($ws)->sole();
        $this->assertSame(2, DB::table('status_page_sections')->where('status_page_id', $page->id)->count());
        $this->assertSame(0, DB::table('status_page_items')->count());
        $this->assertContains('monitors_not_imported', self::codes($this->entries($report, 'status_page')[1]));
    }

    public function test_page_and_layout_commit_together(): void
    {
        $ws = $this->workspace();
        $this->app->bind(CreateStatusPage::class, fn () => new class(app(PageStamps::class)) extends CreateStatusPage
        {
            public function handle(Workspace $workspace, array $validated, ?User $actor, ?\Closure $within = null): StatusPage
            {
                return parent::handle($workspace, $validated, $actor, function (StatusPage $page) use ($within): void {
                    if ($within !== null) {
                        $within($page);
                    }
                    throw new \RuntimeException('boom');
                });
            }
        });

        $report = $this->import(ImporterTest::fixture('kuma2.db'), $ws);

        $this->assertSame(['failed' => 1], $report['counts']['status_page']);
        $this->assertSame(0, StatusPage::count());
        $this->assertSame(0, DB::table('status_page_sections')->count());
        $this->assertSame(0, DB::table('status_page_items')->count());
        $this->assertSame(0, ImportMapping::query()->where('entity', 'status_page')->count());
        $this->assertSame(0, AuditEvent::where('action', 'like', 'status_page.%')->count());
    }

    public function test_incident_is_open_and_linked_to_the_page(): void
    {
        $ws = $this->workspace();

        $report = $this->import(ImporterTest::fixture('kuma2.db'), $ws);

        $incident = Incident::query()->forWorkspace($ws)->sole();
        $page = StatusPage::query()->forWorkspace($ws)->sole();
        $this->assertSame(IncidentStatus::Investigating, $incident->status);
        $this->assertSame('minor', $incident->impact->value);
        $this->assertSame('Degraded API', $incident->title);
        $this->assertSame('2026-11-07 22:32:33', $incident->started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($incident->resolved_at);
        $update = $incident->updates()->sole();
        $this->assertSame('Investigating', $update->message);
        $this->assertSame(IncidentStatus::Investigating, $update->status);
        $this->assertSame([$page->id], DB::table('status_page_links')->where('linkable_type', 'incident')->where('linkable_id', $incident->id)->pluck('status_page_id')->map(fn ($id): int => (int) (is_numeric($id) ? $id : 0))->all());
        $this->assertSame($incident->id, $this->entries($report, 'incident')[1]['target_id']);
    }

    public function test_unpinned_incident_is_resolved_at_its_last_update(): void
    {
        $path = $this->patched('kuma2.db', ["update incident set pin = 0, style = 'danger', last_updated_date = '2026-11-07 23:00:00'"]);
        $ws = $this->workspace();

        $this->import($path, $ws);

        $incident = Incident::query()->forWorkspace($ws)->sole();
        $this->assertSame(IncidentStatus::Resolved, $incident->status);
        $this->assertSame('major', $incident->impact->value);
        $this->assertSame('2026-11-07 23:00:00', $incident->resolved_at?->utc()->format('Y-m-d H:i:s'));
    }

    public function test_incident_of_a_page_that_was_not_imported_is_skipped(): void
    {
        $ws = $this->workspace();

        $report = $this->import(ImporterTest::fixture('kuma2.db'), $ws, importer: new KumaImporter([ImportIncidents::class]));

        $this->assertSame(0, Incident::count());
        $this->assertSame(['status_page_not_imported'], self::codes($this->entries($report, 'incident')[1]));
        $this->assertSame('skipped', $this->entries($report, 'incident')[1]['outcome']);
    }

    public function test_fixture_maintenance_with_monitors_is_imported_and_the_others_are_skipped(): void
    {
        $ws = $this->workspace();

        $report = $this->import(ImporterTest::fixture('kuma2.db'), $ws);

        $this->assertSame(['created' => 1, 'skipped' => 5], $report['counts']['maintenance']);
        $maintenance = Maintenance::query()->forWorkspace($ws)->sole();
        $ids = $this->monitorIds($report);
        $this->assertSame('Weekly patching', $maintenance->title);
        $this->assertSame('weekly', $maintenance->strategy->value);
        $this->assertSame([7], $maintenance->weekdays);
        $this->assertSame('02:00', $maintenance->start_time);
        $this->assertSame(60, $maintenance->duration_minutes);
        $this->assertSame('Europe/Budapest', $maintenance->timezone);
        $this->assertSame('2026-11-01', substr((string) $maintenance->valid_from, 0, 10));
        $this->assertNull($maintenance->valid_until);
        $this->assertTrue($maintenance->active);
        $this->assertEqualsCanonicalizing([$ids[2], $ids[7]], $maintenance->monitors()->pluck('monitors.id')->all());
        $this->assertSame([StatusPage::query()->forWorkspace($ws)->sole()->id], $maintenance->statusPages()->pluck('status_pages.id')->all());

        $entries = $this->entries($report, 'maintenance');
        $this->assertSame(['manual_maintenance'], self::codes($entries[2]));
        foreach ([3, 4, 5, 6] as $id) {
            $this->assertSame(['maintenance_without_monitors'], array_values(array_intersect(self::codes($entries[$id]), ['maintenance_without_monitors'])));
        }
    }

    public function test_every_fixture_strategy_maps_when_it_has_a_monitor(): void
    {
        $links = [];
        foreach ([3, 4, 5, 6] as $id) {
            $links[] = "insert into monitor_maintenance (monitor_id, maintenance_id) values (2, {$id})";
        }
        $ws = $this->workspace('Europe/Berlin');

        $report = $this->import($this->patched('kuma2.db', $links), $ws);

        $this->assertSame(['created' => 5, 'skipped' => 1], $report['counts']['maintenance']);
        $entries = $this->entries($report, 'maintenance');
        $byTitle = fn (string $title): Maintenance => Maintenance::query()->forWorkspace($ws)->where('title', $title)->sole();

        $once = $byTitle('One-off');
        $this->assertSame('once', $once->strategy->value);
        $this->assertSame('UTC', $once->timezone);
        $this->assertSame('2026-12-01 01:00:00', $once->starts_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-01 02:00:00', $once->ends_at?->utc()->format('Y-m-d H:i:s'));

        $cron = $byTitle('Cron nightly');
        $this->assertSame('cron', $cron->strategy->value);
        $this->assertSame('30 3 * * *', $cron->cron);
        $this->assertSame(45, $cron->duration_minutes);

        $interval = $byTitle('Every 3 days');
        $this->assertSame('interval', $interval->strategy->value);
        $this->assertSame(3, $interval->every_days);
        $this->assertSame('02:00', $interval->start_time);
        $this->assertSame('Europe/Berlin', $interval->timezone);
        $this->assertContains('timezone_same_as_server', self::codes($entries[5]));

        $monthly = $byTitle('1st and last of month');
        $this->assertSame('monthly', $monthly->strategy->value);
        $this->assertSame([-1, 1], $monthly->month_days);
    }

    public function test_local_start_times_convert_to_utc_with_the_maintenance_timezone(): void
    {
        $path = $this->patched('kuma2.db', [
            "update maintenance set timezone = 'Europe/Budapest' where id = 3",
            'insert into monitor_maintenance (monitor_id, maintenance_id) values (2, 3)',
        ]);
        $ws = $this->workspace();

        $this->import($path, $ws);

        $once = Maintenance::query()->forWorkspace($ws)->where('title', 'One-off')->sole();
        $this->assertSame('2026-12-01 00:00:00', $once->starts_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-01 01:00:00', $once->ends_at?->utc()->format('Y-m-d H:i:s'));
    }

    public function test_maintenance_page_targets_need_an_imported_page(): void
    {
        $ws = $this->workspace();
        $importer = new KumaImporter([ImportMaintenances::class]);

        $report = $this->import(ImporterTest::fixture('kuma2.db'), $ws, importer: $importer);

        // Without the earlier steps no monitor or page was imported, so the one maintenance has no target left.
        $this->assertSame(['skipped' => 6], $report['counts']['maintenance']);
    }

    public function test_inactive_maintenance_stays_inactive(): void
    {
        $path = $this->patched('kuma2.db', ['update maintenance set active = 0 where id = 1']);
        $ws = $this->workspace();

        $this->import($path, $ws);

        $this->assertFalse(Maintenance::query()->forWorkspace($ws)->sole()->active);
    }

    public function test_rerun_never_updates_and_never_recreates(): void
    {
        $ws = $this->workspace();
        $path = ImporterTest::fixture('kuma2.db');
        $this->import($path, $ws);
        StatusPage::query()->forWorkspace($ws)->update(['title' => 'Edited locally']);
        Incident::query()->forWorkspace($ws)->delete();

        $second = $this->import($path, $ws);

        $this->assertSame(['existing' => 1], $second['counts']['status_page']);
        $this->assertSame(['existing' => 1], $second['counts']['incident']);
        $this->assertSame(['existing' => 1, 'skipped' => 5], $second['counts']['maintenance']);
        $this->assertSame('Edited locally', StatusPage::query()->forWorkspace($ws)->sole()->title);
        $this->assertSame(0, Incident::count());
        $this->assertSame(['deleted_locally'], self::codes($this->entries($second, 'incident')[1]));
        $this->assertSame(['already_imported'], self::codes($this->entries($second, 'status_page')[1]));
        $this->assertSame(1, StatusPage::count());
        $this->assertSame(1, Maintenance::count());
    }

    public function test_deleted_page_is_not_recreated_and_its_incident_is_skipped(): void
    {
        $ws = $this->workspace();
        $path = ImporterTest::fixture('kuma2.db');
        $this->import($path, $ws);
        StatusPage::query()->forWorkspace($ws)->delete();
        Incident::query()->forWorkspace($ws)->delete();
        ImportMapping::query()->forWorkspace($ws)->where('entity', 'incident')->delete();

        $second = $this->import($path, $ws);

        $this->assertSame(['deleted_locally'], self::codes($this->entries($second, 'status_page')[1]));
        $this->assertSame(['status_page_not_imported'], self::codes($this->entries($second, 'incident')[1]));
        $this->assertSame(0, StatusPage::count());
    }

    public function test_dry_run_validates_without_writing_and_resolves_placeholders(): void
    {
        $ws = $this->workspace();

        $report = $this->import(ImporterTest::fixture('kuma2.db'), $ws, dryRun: true);

        $this->assertSame(['would_create' => 1], $report['counts']['status_page']);
        $this->assertSame(['would_create' => 1], $report['counts']['incident']);
        $this->assertSame(['would_create' => 1, 'skipped' => 5], $report['counts']['maintenance']);
        foreach ([StatusPage::class, Incident::class, Maintenance::class, Monitor::class, ImportMapping::class] as $model) {
            $this->assertSame(0, $model::count());
        }
        $this->assertSame(0, DB::table('status_page_sections')->count());
        $this->assertSame(0, AuditEvent::where('action', 'like', 'status_page.%')->count());
    }

    public function test_dry_run_reports_invalid_definitions(): void
    {
        $path = $this->patched('kuma2.db', ["update maintenance set cron = 'not a cron' where id = 4", 'insert into monitor_maintenance (monitor_id, maintenance_id) values (2, 4)']);

        $report = $this->import($path, $this->workspace(), dryRun: true);

        $this->assertSame('invalid', $this->entries($report, 'maintenance')[4]['outcome']);
        $this->assertArrayHasKey('cron', $this->entries($report, 'maintenance')[4]['errors'] ?? []);
    }

    public function test_invalid_page_does_not_stop_the_import(): void
    {
        $path = $this->patched('kuma2.db', ["update status_page set title = ''"]);
        $ws = $this->workspace();

        $report = $this->import($path, $ws);

        $this->assertSame(['invalid' => 1], $report['counts']['status_page']);
        $this->assertArrayHasKey('title', $this->entries($report, 'status_page')[1]['errors'] ?? []);
        $this->assertSame(0, StatusPage::count());
        $this->assertSame(['skipped' => 1], $report['counts']['incident']);
        $this->assertSame(['created' => 9, 'skipped' => 1], $report['counts']['monitor']);
    }

    public function test_the_1_23_fixture_imports_its_page_incident_and_maintenance(): void
    {
        $ws = $this->workspace();

        $report = $this->import(ImporterTest::fixture('kuma1.db'), $ws);

        $this->assertSame(['created' => 1], $report['counts']['status_page']);
        $this->assertSame(['created' => 1], $report['counts']['incident']);
        $this->assertSame(['created' => 1, 'skipped' => 2], $report['counts']['maintenance']);
        $this->assertSame('status.example.com', StatusPage::query()->forWorkspace($ws)->sole()->custom_domain);
        $this->assertSame(4, DB::table('status_page_items')->count());
        $this->assertSame(['manual_maintenance'], self::codes($this->entries($report, 'maintenance')[2]));
    }

    public function test_unexpected_failure_logs_only_class_and_source_id(): void
    {
        $this->app->bind(CreateStatusPage::class, fn () => new class(app(PageStamps::class)) extends CreateStatusPage
        {
            public function handle(Workspace $workspace, array $validated, ?User $actor, ?\Closure $within = null): StatusPage
            {
                throw new \RuntimeException('SQLSTATE bindings S3cr3t-binding');
            }
        });
        $logged = [];
        Log::listen(function (MessageLogged $m) use (&$logged): void {
            $logged[] = $m->message.json_encode($m->context);
        });

        $report = $this->import(ImporterTest::fixture('kuma2.db'), $this->workspace());

        $this->assertSame(['failed' => 1], $report['counts']['status_page']);
        $this->assertSame(['unexpected_error'], self::codes($this->entries($report, 'status_page')[1]));
        $this->assertStringNotContainsString('S3cr3t-binding', json_encode([$report, $logged]) ?: '');
        $this->assertStringContainsString('RuntimeException', implode("\n", $logged));
    }

    public function test_page_password_and_analytics_values_never_reach_the_report(): void
    {
        $path = $this->patched('kuma2.db', ["update status_page set password = 'S3cr3t-page-pw', analytics_id = 'G-S3CR3T'"]);

        $report = $this->import($path, $this->workspace());

        $haystack = json_encode([$report, AuditEvent::all()->toArray()]) ?: '';
        $this->assertStringNotContainsString('S3cr3t-page-pw', $haystack);
        $this->assertStringNotContainsString('G-S3CR3T', $haystack);
        $this->assertStringContainsString('password', $haystack);
    }
}
