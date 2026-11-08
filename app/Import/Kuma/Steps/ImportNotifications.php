<?php

declare(strict_types=1);

namespace App\Import\Kuma\Steps;

use App\Actions\Notifications\CreateNotificationChannel;
use App\Enums\ImportOutcome;
use App\Import\Kuma\ImportContext;
use App\Import\Kuma\Mapping\Mapped;
use App\Import\Kuma\Mapping\Note;
use App\Import\Kuma\Mapping\NotificationMapper;
use App\Models\NotificationChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ImportNotifications implements ImportStep
{
    public const ENTITY = 'notification';

    public function __construct(
        private readonly NotificationMapper $mapper,
        private readonly CreateNotificationChannel $create,
    ) {}

    public function run(ImportContext $ctx): void
    {
        foreach ($ctx->source->notifications() as $row) {
            $sourceId = $row['id'];
            $label = $row['name'];

            try {
                $this->import($ctx, $sourceId, $row);
            } catch (Throwable $e) {
                Log::warning('Kuma notification import failed', ['source_id' => $sourceId, 'exception' => $e::class]);
                $ctx->report->add(self::ENTITY, $sourceId, $label, ImportOutcome::Failed, null, [
                    Note::of('unexpected_error', 'The notification channel could not be imported; see the application log.'),
                ]);
            }
        }
    }

    /**
     * @param  array{id: int, name: string, active: bool, is_default: bool, config: array<string, mixed>}  $row
     */
    private function import(ImportContext $ctx, int $sourceId, array $row): void
    {
        $label = $row['name'];

        $mapping = $ctx->mappings->find(self::ENTITY, $sourceId);
        if ($mapping !== null) {
            $stillThere = NotificationChannel::query()->forWorkspace($ctx->workspace)->whereKey($mapping->target_id)->exists();
            $note = $stillThere
                ? Note::of('already_imported', 'The notification channel was imported earlier; it was left unchanged.')
                : Note::of('deleted_locally', 'The notification channel was imported earlier and has since been deleted; it was not recreated.');
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

        try {
            $validated = $this->create->validate($ctx->workspace, $mapped->attributes);
        } catch (ValidationException $e) {
            $ctx->report->add(self::ENTITY, $sourceId, $name, ImportOutcome::Invalid, null, [
                Note::of('channel_invalid', 'The channel did not pass IntraWatch validation.'),
            ], $this->errors($e));

            return;
        }

        if ($ctx->options->dryRun) {
            $ctx->mappings->record(self::ENTITY, $sourceId, new NotificationChannel);
            $ctx->report->add(self::ENTITY, $sourceId, $name, ImportOutcome::WouldCreate, null, $mapped->notes);

            return;
        }

        $channel = DB::transaction(function () use ($ctx, $sourceId, $validated): NotificationChannel {
            $channel = $this->create->handle($ctx->workspace, $validated, $ctx->actor);
            $ctx->mappings->record(self::ENTITY, $sourceId, $channel);

            return $channel;
        });

        $ctx->report->add(self::ENTITY, $sourceId, $name, ImportOutcome::Created, $channel->id, $mapped->notes);
    }

    /**
     * Field-level messages only; they name fields, never the submitted values.
     *
     * @return array<string, list<string>>
     */
    private function errors(ValidationException $e): array
    {
        $errors = [];
        foreach ($e->validator->errors()->messages() as $field => $messages) {
            $errors[(string) $field] = array_values($messages);
        }

        return $errors;
    }
}
