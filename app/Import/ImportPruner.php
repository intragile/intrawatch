<?php

namespace App\Import;

use App\Enums\ImportStatus;
use App\Models\Import;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * Cleans up after imports: an import whose job has not changed its state for two hours was interrupted (the
 * worker died or the job was lost) and is marked failed, and uploaded files are kept for at most a day. An
 * analysed import whose file expired can no longer be run, so it is marked failed as well.
 */
final class ImportPruner
{
    public const DIRECTORY = 'imports';

    public const INTERRUPTED = 'interrupted';

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

        $expired = Import::query()
            ->whereNotNull('file_path')
            ->where('created_at', '<', $now->subHours(self::FILE_HOURS))
            ->lazyById();
        foreach ($expired as $import) {
            $files += $import->status === ImportStatus::Analyzed
                ? $this->finish($import, self::EXPIRED)
                : $this->deleteFile($import);
        }

        $files += $this->deleteOrphans($now->subHours(self::FILE_HOURS)->getTimestamp());

        return ['import_files' => $files, 'interrupted_imports' => $interrupted];
    }

    private function finish(Import $import, string $error): int
    {
        $deleted = $this->deleteFile($import);
        $import->forceFill(['status' => ImportStatus::Failed, 'error' => $error, 'finished_at' => CarbonImmutable::now()])->save();

        return $deleted;
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
}
