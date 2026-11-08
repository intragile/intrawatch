<?php

declare(strict_types=1);

namespace App\Import\Kuma\Steps;

use App\Actions\Incidents\CreateIncident;
use App\Enums\ImportOutcome;
use App\Import\Kuma\ImportContext;
use App\Import\Kuma\Mapping\IncidentMapper;
use App\Import\Kuma\Mapping\Note;
use App\Import\Kuma\Source\Cast;
use App\Import\Kuma\Steps\Concerns\ResolvesImportedTargets;
use App\Models\Incident;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Imports the incidents of the imported status pages. Kuma ties an incident to one page; so does the import.
 */
final class ImportIncidents implements ImportStep
{
    use ResolvesImportedTargets;

    public const ENTITY = 'incident';

    public function __construct(
        private readonly IncidentMapper $mapper,
        private readonly CreateIncident $create,
    ) {}

    public function run(ImportContext $ctx): void
    {
        foreach ($ctx->source->incidents() as $row) {
            $sourceId = Cast::int($row['id'] ?? null);
            $label = Cast::string($row['title'] ?? null);

            try {
                $this->import($ctx, $sourceId, $row);
            } catch (Throwable $e) {
                Log::warning('Kuma incident import failed', ['source_id' => $sourceId, 'exception' => $e::class]);
                $ctx->report->add(self::ENTITY, $sourceId, $label, ImportOutcome::Failed, null, [
                    Note::of('unexpected_error', 'The incident could not be imported; see the application log.'),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function import(ImportContext $ctx, int $sourceId, array $row): void
    {
        $label = Cast::string($row['title'] ?? null);

        $mapping = $ctx->mappings->find(self::ENTITY, $sourceId);
        if ($mapping !== null) {
            $stillThere = Incident::query()->forWorkspace($ctx->workspace)->whereKey($mapping->target_id)->exists();
            $note = $stillThere
                ? Note::of('already_imported', 'The incident was imported earlier; it was left unchanged.')
                : Note::of('deleted_locally', 'The incident was imported earlier and has since been deleted; it was not recreated.');
            $ctx->report->add(self::ENTITY, $sourceId, $label, ImportOutcome::Existing, $mapping->target_id, [$note]);

            return;
        }

        $pageId = $this->resolvePage($ctx, Cast::int($row['status_page_id'] ?? null));
        if ($pageId === null) {
            $ctx->report->add(self::ENTITY, $sourceId, $label, ImportOutcome::Skipped, null, [
                Note::of('status_page_not_imported', 'The incident belongs to a status page that was not imported, so it was skipped.'),
            ]);

            return;
        }

        $mapped = $this->mapper->map($row, CarbonImmutable::now());
        $input = $mapped->attributes + ['status_page_ids' => [$pageId]];
        $resolvedAt = $input['resolved_at'] ?? null;
        unset($input['resolved_at']);
        $name = is_string($input['title'] ?? null) && $input['title'] !== '' ? $input['title'] : $label;

        try {
            $validated = $this->create->validate($ctx->workspace, $input, ! $ctx->options->dryRun);
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->validator->errors()->messages() as $field => $messages) {
                $errors[(string) $field] = array_values($messages);
            }
            $ctx->report->add(self::ENTITY, $sourceId, $name, ImportOutcome::Invalid, null, [
                Note::of('incident_invalid', 'The incident did not pass IntraWatch validation.'),
            ], $errors);

            return;
        }

        if ($ctx->options->dryRun) {
            $ctx->mappings->record(self::ENTITY, $sourceId, new Incident);
            $ctx->report->add(self::ENTITY, $sourceId, $name, ImportOutcome::WouldCreate, null, $mapped->notes);

            return;
        }

        if (is_string($resolvedAt) && ! $validated['status']->isOpen()) {
            $validated['resolved_at'] = CarbonImmutable::parse($resolvedAt);
        }
        $incident = $this->create->handle($ctx->workspace, $validated, $ctx->actor, function (Incident $incident) use ($ctx, $sourceId): void {
            $ctx->mappings->record(self::ENTITY, $sourceId, $incident);
        });

        $ctx->report->add(self::ENTITY, $sourceId, $name, ImportOutcome::Created, $incident->id, $mapped->notes);
    }
}
