<?php

declare(strict_types=1);

namespace App\Import\Kuma;

use App\Enums\ImportOutcome;
use App\Import\Kuma\Mapping\Note;
use App\Import\Kuma\Source\KumaSource;
use Illuminate\Support\Carbon;

/**
 * @phpstan-type NoteShape array{code: string, message: string}
 * @phpstan-type EntryShape array{entity: string, source_id: int, name: string, outcome: string, target_id: int|null, notes: list<NoteShape>, errors?: array<string, list<string>>}
 * @phpstan-type NotMigratedShape array{users: int, api_keys: int, docker_hosts: int, remote_browsers: int}
 * @phpstan-type ReportShape array{source: array{kind: string, version: string}, dry_run: bool, started_at: string, finished_at: string, duration_ms: int, counts: array<string, array<string, int>>, entries: list<EntryShape>, not_migrated: NotMigratedShape, history: array{monitors: int, hour_rows: int, day_rows: int, notes: list<NoteShape>}, notes: list<NoteShape>}
 *
 * Collects what an import did, one entry per source row. It never carries credentials: callers pass names,
 * ids, notes and field-level messages only.
 */
final class ImportReport
{
    private Carbon $startedAt;

    private ?Carbon $finishedAt = null;

    /** @var list<EntryShape> */
    private array $entries = [];

    /** @var array<string, array<string, int>> */
    private array $counts = [];

    /** @var list<Note> */
    private array $notes = [];

    /** @var list<Note> */
    private array $historyNotes = [];

    private int $historyMonitors = 0;

    private int $hourRows = 0;

    private int $dayRows = 0;

    /** @var NotMigratedShape */
    private array $notMigrated;

    private string $kind;

    private string $version;

    public function __construct(KumaSource $source, private readonly bool $dryRun)
    {
        $this->kind = $source->kind()->value;
        $this->version = $source->version();
        $this->notMigrated = $source->notMigratedCounts();
        $this->startedAt = Carbon::now();
    }

    /**
     * @param  list<Note>  $notes
     * @param  array<string, list<string>>  $errors
     */
    public function add(string $entity, int $sourceId, string $name, ImportOutcome $outcome, ?int $targetId = null, array $notes = [], array $errors = []): void
    {
        $entry = [
            'entity' => $entity,
            'source_id' => $sourceId,
            'name' => $name,
            'outcome' => $outcome->value,
            'target_id' => $targetId,
            'notes' => array_map(static fn (Note $n): array => $n->toArray(), $notes),
        ];
        if ($errors !== []) {
            $entry['errors'] = $errors;
        }

        $this->entries[] = $entry;
        $this->counts[$entity][$outcome->value] = ($this->counts[$entity][$outcome->value] ?? 0) + 1;
    }

    public function note(Note $note): void
    {
        $this->notes[] = $note;
    }

    public function historyNote(Note $note): void
    {
        $this->historyNotes[] = $note;
    }

    /**
     * Adds to the imported-history totals.
     */
    public function addHistory(int $monitors, int $hourRows, int $dayRows): void
    {
        $this->historyMonitors += $monitors;
        $this->hourRows += $hourRows;
        $this->dayRows += $dayRows;
    }

    public function finish(): void
    {
        $this->finishedAt = Carbon::now();
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function counts(): array
    {
        return $this->counts;
    }

    /**
     * @return list<NoteShape>
     */
    public function notes(): array
    {
        return array_map(static fn (Note $n): array => $n->toArray(), $this->notes);
    }

    /**
     * @return NotMigratedShape
     */
    public function notMigrated(): array
    {
        return $this->notMigrated;
    }

    /**
     * @return ReportShape
     */
    public function toArray(): array
    {
        $finished = $this->finishedAt ?? Carbon::now();

        return [
            'source' => ['kind' => $this->kind, 'version' => $this->version],
            'dry_run' => $this->dryRun,
            'started_at' => $this->startedAt->toIso8601String(),
            'finished_at' => $finished->toIso8601String(),
            'duration_ms' => (int) round($this->startedAt->diffInMilliseconds($finished, true)),
            'counts' => $this->counts,
            'entries' => $this->entries,
            'not_migrated' => $this->notMigrated,
            'history' => [
                'monitors' => $this->historyMonitors,
                'hour_rows' => $this->hourRows,
                'day_rows' => $this->dayRows,
                'notes' => array_map(static fn (Note $n): array => $n->toArray(), $this->historyNotes),
            ],
            'notes' => $this->notes(),
        ];
    }
}
