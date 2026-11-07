<?php

declare(strict_types=1);

namespace Tests\Feature\Import\Kuma;

use App\Enums\ImportOutcome;
use App\Import\Kuma\ImportOptions;
use App\Import\Kuma\ImportReport;
use App\Import\Kuma\KumaImporter;
use App\Import\Kuma\MappingStore;
use App\Import\Kuma\Source\KumaSource;
use App\Import\Kuma\Source\SourceFactory;
use App\Models\AuditEvent;
use App\Models\ImportMapping;
use App\Models\Tag;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @phpstan-import-type ReportShape from ImportReport
 * @phpstan-import-type EntryShape from ImportReport
 */
final class ImporterTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<KumaSource> */
    private array $sources = [];

    protected function tearDown(): void
    {
        foreach ($this->sources as $source) {
            $source->close();
        }
        parent::tearDown();
    }

    public static function fixture(string $name): string
    {
        return dirname(__DIR__, 3).'/Fixtures/kuma/'.$name;
    }

    private function open(string $name): KumaSource
    {
        return $this->sources[] = SourceFactory::open(self::fixture($name));
    }

    /**
     * @return ReportShape
     */
    private function import(string $fixture, Workspace $workspace, bool $dryRun = false): array
    {
        return app(KumaImporter::class)
            ->run($this->open($fixture), $workspace, new ImportOptions(dryRun: $dryRun), null)
            ->toArray();
    }

    /**
     * @param  ReportShape  $report
     * @return list<EntryShape>
     */
    private function tagEntries(array $report): array
    {
        return array_values(array_filter($report['entries'], fn (array $e): bool => $e['entity'] === 'tag'));
    }

    public function test_dry_run_creates_nothing_and_reports_would_create(): void
    {
        $ws = Workspace::factory()->create();

        $report = $this->import('kuma2.db', $ws, dryRun: true);

        $this->assertTrue($report['dry_run']);
        $this->assertSame(['would_create' => 1], $report['counts']['tag']);
        $this->assertSame('prod', $this->tagEntries($report)[0]['name']);
        $this->assertSame(0, Tag::count());
        $this->assertSame(0, ImportMapping::count());
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_import_creates_tag_and_mapping(): void
    {
        $ws = Workspace::factory()->create();

        $report = $this->import('kuma2.db', $ws);

        $this->assertSame(['created' => 1], $report['counts']['tag']);
        $tag = Tag::where('workspace_id', $ws->id)->sole();
        $this->assertSame('prod', $tag->name);
        $this->assertSame('#059669', $tag->color);
        $mapping = ImportMapping::where('entity', 'tag')->sole();
        $this->assertSame($ws->id, $mapping->workspace_id);
        $this->assertSame('uptime_kuma', $mapping->source);
        $this->assertSame('tag', $mapping->entity);
        $this->assertSame(1, $mapping->source_id);
        $this->assertSame($tag->id, $mapping->target_id);
        $this->assertSame($tag->id, $this->tagEntries($report)[0]['target_id']);
        $this->assertSame(['source', 'dry_run', 'started_at', 'finished_at', 'duration_ms', 'counts', 'entries', 'not_migrated', 'history', 'notes'], array_keys($report));
    }

    public function test_existing_tag_with_same_name_is_reused(): void
    {
        $ws = Workspace::factory()->create();
        $local = Tag::factory()->create(['workspace_id' => $ws->id, 'name' => 'prod', 'color' => '#111111']);

        $report = $this->import('kuma2.db', $ws);

        $entry = $this->tagEntries($report)[0];
        $this->assertSame(ImportOutcome::Existing->value, $entry['outcome']);
        $this->assertSame('tag_reused', $entry['notes'][0]['code']);
        $this->assertSame(1, Tag::count());
        $this->assertSame('#111111', $local->fresh()?->color);
        $this->assertSame($local->id, ImportMapping::where('entity', 'tag')->sole()->target_id);
    }

    public function test_rerun_reports_existing_and_creates_nothing(): void
    {
        $ws = Workspace::factory()->create();
        $this->import('kuma2.db', $ws);
        Tag::query()->update(['color' => '#222222']);

        $report = $this->import('kuma2.db', $ws);

        $this->assertSame(['existing' => 1], $report['counts']['tag']);
        $this->assertSame('already_imported', $this->tagEntries($report)[0]['notes'][0]['code']);
        $this->assertSame(1, Tag::count());
        $this->assertSame(1, ImportMapping::where('entity', 'tag')->count());
        $this->assertSame('#222222', Tag::sole()->color);

        Tag::query()->delete();
        $report = $this->import('kuma2.db', $ws);
        $this->assertSame(['existing' => 1], $report['counts']['tag']);
        $this->assertSame('deleted_locally', $this->tagEntries($report)[0]['notes'][0]['code']);
        $this->assertSame(0, Tag::count());
    }

    public function test_mapping_is_per_workspace(): void
    {
        $a = Workspace::factory()->create();
        $b = Workspace::factory()->create();

        $this->import('kuma2.db', $a);
        $report = $this->import('kuma2.db', $b);

        $this->assertSame(['created' => 1], $report['counts']['tag']);
        $this->assertSame(1, Tag::where('workspace_id', $b->id)->count());
        $this->assertSame(2, ImportMapping::where('entity', 'tag')->count());
    }

    public function test_json_then_db_warns_about_other_instance_kind(): void
    {
        $ws = Workspace::factory()->create();

        $first = $this->import('kuma1-backup.json', $ws);
        $this->assertSame([], $first['notes']);
        $this->assertGreaterThan(0, ImportMapping::count());

        $second = $this->import('kuma2.db', $ws);
        $this->assertSame('other_instance_kind', $second['notes'][0]['code']);

        $third = $this->import('kuma1-backup.json', $ws);
        $this->assertSame('other_instance_kind', $third['notes'][0]['code']);
    }

    public function test_created_in_run_exposes_placeholders_in_dry_run(): void
    {
        $ws = Workspace::factory()->create();
        $source = $this->open('kuma2.db');
        $store = new MappingStore($ws, $source, true);

        $store->record('tag', 1, new Tag);

        $this->assertLessThan(0, $store->targetId('tag', 1));
        $this->assertSame([1 => $store->targetId('tag', 1)], $store->createdInRun('tag'));
        $this->assertSame(0, ImportMapping::count());
    }
}
