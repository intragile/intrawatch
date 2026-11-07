<?php

declare(strict_types=1);

namespace App\Import\Kuma\Steps;

use App\Actions\Tags\FindOrCreateTag;
use App\Enums\ImportOutcome;
use App\Import\Kuma\ImportContext;
use App\Import\Kuma\Mapping\Mapped;
use App\Import\Kuma\Mapping\Note;
use App\Import\Kuma\Mapping\TagMapper;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ImportTags implements ImportStep
{
    public const ENTITY = 'tag';

    public function __construct(
        private readonly TagMapper $mapper,
        private readonly FindOrCreateTag $findOrCreate,
    ) {}

    public function run(ImportContext $ctx): void
    {
        foreach ($ctx->source->tags() as $row) {
            $sourceId = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            $label = is_scalar($row['name'] ?? null) ? (string) $row['name'] : '';

            try {
                $this->import($ctx, $sourceId, $row);
            } catch (Throwable $e) {
                report($e);
                Log::warning('Kuma tag import failed', ['source_id' => $sourceId, 'exception' => $e::class]);
                $ctx->report->add(self::ENTITY, $sourceId, $label, ImportOutcome::Failed, null, [
                    Note::of('unexpected_error', 'The tag could not be imported; see the application log.'),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function import(ImportContext $ctx, int $sourceId, array $row): void
    {
        $label = is_scalar($row['name'] ?? null) ? (string) $row['name'] : '';

        $mapping = $ctx->mappings->find(self::ENTITY, $sourceId);
        if ($mapping !== null) {
            $stillThere = Tag::query()->forWorkspace($ctx->workspace)->whereKey($mapping->target_id)->exists();
            $note = $stillThere
                ? Note::of('already_imported', 'The tag was imported earlier; it was left unchanged.')
                : Note::of('deleted_locally', 'The tag was imported earlier and has since been deleted; it was not recreated.');
            $ctx->report->add(self::ENTITY, $sourceId, $label, ImportOutcome::Existing, $mapping->target_id, [$note]);

            return;
        }

        $mapped = $this->mapper->map($row);
        if (! $mapped instanceof Mapped) {
            $outcome = $mapped->errors === [] ? ImportOutcome::Skipped : ImportOutcome::Invalid;
            $ctx->report->add(self::ENTITY, $sourceId, $label, $outcome, null, [$mapped->reason], $mapped->errors);

            return;
        }

        $name = is_string($mapped->attributes['name'] ?? null) ? $mapped->attributes['name'] : $label;
        $color = is_string($mapped->attributes['color'] ?? null) ? $mapped->attributes['color'] : null;
        $notes = $mapped->notes;

        if ($ctx->options->dryRun) {
            $local = Tag::query()->forWorkspace($ctx->workspace)->where('name', $name)->first();
            if ($local !== null) {
                $ctx->mappings->record(self::ENTITY, $sourceId, $local, false);
                $ctx->report->add(self::ENTITY, $sourceId, $name, ImportOutcome::Existing, $local->id, [...$notes, $this->reused()]);

                return;
            }
            $ctx->mappings->record(self::ENTITY, $sourceId, new Tag);
            $ctx->report->add(self::ENTITY, $sourceId, $name, ImportOutcome::WouldCreate, null, $notes);

            return;
        }

        [$tag, $created] = DB::transaction(function () use ($ctx, $sourceId, $name, $color): array {
            [$tag, $created] = $this->findOrCreate->handle($ctx->workspace, $name, $color);
            $ctx->mappings->record(self::ENTITY, $sourceId, $tag, $created);

            return [$tag, $created];
        });

        if ($created) {
            $ctx->report->add(self::ENTITY, $sourceId, $name, ImportOutcome::Created, $tag->id, $notes);
        } else {
            $ctx->report->add(self::ENTITY, $sourceId, $name, ImportOutcome::Existing, $tag->id, [...$notes, $this->reused()]);
        }
    }

    private function reused(): Note
    {
        return Note::of('tag_reused', 'A tag with this name already exists in the workspace and was reused.');
    }
}
