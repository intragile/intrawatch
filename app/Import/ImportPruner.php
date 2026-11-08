<?php

namespace App\Import;

use App\Enums\ImportStatus;
use App\Models\Import;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Cleans up after imports: an import whose job has not changed its state for two hours was interrupted (the
 * worker died or the job was lost) and is marked failed, and uploaded files are kept for at most a day. An
 * analysed import whose file expired can no longer be run, so it is marked failed as well. Every state change
 * is conditional on the state the pruner read, so an import the job moved on in the meantime is left alone.
 * Temporary database copies left behind by a killed job are removed after two hours.
 */
final class ImportPruner
{
    public const DIRECTORY = 'imports';

    public const TEMP_DIRECTORY = 'imports/tmp';

    public const INTERRUPTED = 'The import was interrupted because the worker stopped before it finished. Upload the file again.';

    public const EXPIRED = 'The uploaded file expired before the import was run. Upload it again.';

    private const STALE_HOURS = 2;

    private const FILE_HOURS = 24;

    /**
     * @return array{import_files: int, interrupted_imports: int}
     */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $files = 0;
        $interrupted = 0;

        $stale = Import::query()
            ->whereIn('status', [ImportStatus::Pending, ImportStatus::Analyzing, ImportStatus::Running])
            ->where('updated_at', '<', $now->subHours(self::STALE_HOURS))
            ->lazyById();
        foreach ($stale as $import) {
            $files += $this->finish($import, self::INTERRUPTED);
            $interrupted++;
        }

        // Analysed imports age from the upload, finished ones from the moment they finished.
        $cutoff = $now->subHours(self::FILE_HOURS);
        $expired = Import::query()
            ->whereNotNull('file_path')
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where(fn (Builder $q) => $q->where('status', ImportStatus::Analyzed)->where('created_at', '<', $cutoff))
                    ->orWhere(fn (Builder $q) => $q->whereIn('status', [ImportStatus::Completed, ImportStatus::Failed])
                        ->where(fn (Builder $age) => $age->where('finished_at', '<', $cutoff)
                            ->orWhere(fn (Builder $unset) => $unset->whereNull('finished_at')->where('created_at', '<', $cutoff))));
            })
            ->lazyById();
        foreach ($expired as $import) {
            $files += $import->status === ImportStatus::Analyzed
                ? $this->finish($import, self::EXPIRED)
                : $this->deleteFile($import);
        }

        $files += $this->deleteOrphans($now->subHours(self::FILE_HOURS)->getTimestamp());
        $files += $this->deleteTempCopies($now->subHours(self::STALE_HOURS)->getTimestamp());

        return ['import_files' => $files, 'interrupted_imports' => $interrupted];
    }

    /**
     * The directory that holds the private SQLite copies imports read from.
     */
    public static function tempDirectory(): string
    {
        return Storage::disk('local')->path(self::TEMP_DIRECTORY);
    }

    /**
     * Marks the import failed unless its job changed its state since it was read; only then is its file deleted.
     */
    private function finish(Import $import, string $error): int
    {
        $now = CarbonImmutable::now();
        $changed = Import::query()->whereKey($import->getKey())->where('status', $import->status)
            ->update(['status' => ImportStatus::Failed, 'error' => $error, 'finished_at' => $now, 'updated_at' => $now]);
        if ($changed !== 1) {
            return 0;
        }
        $import->forceFill(['status' => ImportStatus::Failed, 'error' => $error, 'finished_at' => $now, 'updated_at' => $now])->syncOriginal();

        return $this->deleteFile($import);
    }

    private function deleteFile(Import $import): int
    {
        if ($import->file_path === null) {
            return 0;
        }
        $deleted = Storage::disk('local')->delete($import->file_path) ? 1 : 0;
        $import->forceFill(['file_path' => null])->save();

        return $deleted;
    }

    /**
     * Old files no import refers to any more, for example after their workspace was deleted.
     */
    private function deleteOrphans(int $cutoff): int
    {
        $disk = Storage::disk('local');
        $deleted = 0;
        foreach ($disk->files(self::DIRECTORY) as $path) {
            if ($disk->lastModified($path) >= $cutoff || Import::query()->where('file_path', $path)->exists()) {
                continue;
            }
            $deleted += $disk->delete($path) ? 1 : 0;
        }

        return $deleted;
    }

    /**
     * Copies left behind by a killed job or command. A copy is opened read-only, so its modification time is when
     * it was made; queued imports time out long before the cutoff.
     */
    private function deleteTempCopies(int $cutoff): int
    {
        $disk = Storage::disk('local');
        $deleted = 0;
        foreach ($disk->files(self::TEMP_DIRECTORY) as $path) {
            if ($disk->lastModified($path) < $cutoff) {
                $deleted += $disk->delete($path) ? 1 : 0;
            }
        }

        return $deleted;
    }
}
