<?php

declare(strict_types=1);

namespace App\Import\Kuma\Steps;

use App\Actions\Maintenances\CreateMaintenance;
use App\Enums\ImportOutcome;
use App\Import\Kuma\ImportContext;
use App\Import\Kuma\Mapping\MaintenanceMapper;
use App\Import\Kuma\Mapping\Mapped;
use App\Import\Kuma\Mapping\Note;
use App\Import\Kuma\Source\Cast;
use App\Import\Kuma\Steps\Concerns\ResolvesImportedTargets;
use App\Models\Maintenance;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Imports Kuma maintenances that run on a schedule and cover at least one imported monitor. The status pages
 * they are announced on are the imported ones.
 */
final class ImportMaintenances implements ImportStep
{
    use ResolvesImportedTargets;

    public const ENTITY = 'maintenance';

    public function __construct(
        private readonly MaintenanceMapper $mapper,
        private readonly CreateMaintenance $create,
    ) {}

    public function run(ImportContext $ctx): void
    {
        $monitors = $this->links($ctx->source->maintenanceMonitors(), 'monitor_id');
        $pages = $this->links($ctx->source->maintenanceStatusPages(), 'status_page_id');

        foreach ($ctx->source->maintenances() as $row) {
            $sourceId = Cast::int($row['id'] ?? null);
            $label = Cast::string($row['title'] ?? null);

            try {
                $this->import($ctx, $sourceId, $row, $monitors[$sourceId] ?? [], $pages[$sourceId] ?? []);
            } catch (Throwable $e) {
                Log::warning('Kuma maintenance import failed', ['source_id' => $sourceId, 'exception' => $e::class]);
                $ctx->report->add(self::ENTITY, $sourceId, $label, ImportOutcome::Failed, null, [
                    Note::of('unexpected_error', 'The maintenance could not be imported; see the application log.'),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<int>  $kumaMonitorIds
     * @param  list<int>  $kumaPageIds
     */
    private function import(ImportContext $ctx, int $sourceId, array $row, array $kumaMonitorIds, array $kumaPageIds): void
    {
        $label = Cast::string($row['title'] ?? null);

        $mapping = $ctx->mappings->find(self::ENTITY, $sourceId);
        if ($mapping !== null) {
            $stillThere = Maintenance::query()->forWorkspace($ctx->workspace)->whereKey($mapping->target_id)->exists();
            $note = $stillThere
                ? Note::of('already_imported', 'The maintenance was imported earlier; it was left unchanged.')
                : Note::of('deleted_locally', 'The maintenance was imported earlier and has since been deleted; it was not recreated.');
            $ctx->report->add(self::ENTITY, $sourceId, $label, ImportOutcome::Existing, $mapping->target_id, [$note]);

            return;
        }

        $mapped = $this->mapper->map($row, $ctx->workspace->timezone);
        if (! $mapped instanceof Mapped) {
            $ctx->report->add(self::ENTITY, $sourceId, $label, ImportOutcome::Skipped, null, [$mapped->reason]);

            return;
        }

        $notes = $mapped->notes;
        $monitorIds = $this->resolved($kumaMonitorIds, fn (int $id): ?int => $this->resolveMonitor($ctx, $id));
        if ($monitorIds === []) {
            $notes[] = Note::of('maintenance_without_monitors', 'None of the monitors of the maintenance was imported, so it would mute nothing and was skipped.');
            $ctx->report->add(self::ENTITY, $sourceId, $label, ImportOutcome::Skipped, null, [$notes[count($notes) - 1]]);

            return;
        }
        $missing = count($kumaMonitorIds) - count($monitorIds);
        if ($missing > 0) {
            $notes[] = Note::of('monitors_not_imported', "{$missing} monitor(s) of the maintenance were not imported and are not covered.");
        }

        $pageIds = $this->resolved($kumaPageIds, fn (int $id): ?int => $this->resolvePage($ctx, $id));
        $missingPages = count($kumaPageIds) - count($pageIds);
        if ($missingPages > 0) {
            $notes[] = Note::of('status_pages_not_imported', "{$missingPages} status page(s) of the maintenance were not imported, so it is not announced there.");
        }

        $input = $mapped->attributes + ['monitor_ids' => $monitorIds, 'status_page_ids' => $pageIds];
        $name = is_string($input['title'] ?? null) && $input['title'] !== '' ? $input['title'] : $label;

        try {
            $validated = $this->create->validate($ctx->workspace, $input, ! $ctx->options->dryRun);
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->validator->errors()->messages() as $field => $messages) {
                $errors[(string) $field] = array_values($messages);
            }
            $ctx->report->add(self::ENTITY, $sourceId, $name, ImportOutcome::Invalid, null, [
                Note::of('maintenance_invalid', 'The maintenance did not pass IntraWatch validation.'),
            ], $errors);

            return;
        }

        if ($ctx->options->dryRun) {
            $ctx->mappings->record(self::ENTITY, $sourceId, new Maintenance);
            $ctx->report->add(self::ENTITY, $sourceId, $name, ImportOutcome::WouldCreate, null, $notes);

            return;
        }

        $maintenance = $this->create->handle($ctx->workspace, $validated, $ctx->actor, function (Maintenance $maintenance) use ($ctx, $sourceId): void {
            $ctx->mappings->record(self::ENTITY, $sourceId, $maintenance);
        });

        $ctx->report->add(self::ENTITY, $sourceId, $name, ImportOutcome::Created, $maintenance->id, $notes);
    }

    /**
     * Kuma ids grouped by maintenance id.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, list<int>>
     */
    private function links(array $rows, string $column): array
    {
        $links = [];
        foreach ($rows as $row) {
            $links[Cast::int($row['maintenance_id'] ?? null)][] = Cast::int($row[$column] ?? null);
        }

        return $links;
    }

    /**
     * Distinct ids the resolver found, in Kuma id order.
     *
     * @param  list<int>  $kumaIds
     * @param  \Closure(int): ?int  $resolve
     * @return list<int>
     */
    private function resolved(array $kumaIds, \Closure $resolve): array
    {
        sort($kumaIds);
        $ids = [];
        foreach ($kumaIds as $kumaId) {
            $id = $resolve($kumaId);
            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
