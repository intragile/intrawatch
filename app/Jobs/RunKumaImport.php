<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Import\ImportPruner;
use App\Import\Kuma\ImportOptions;
use App\Import\Kuma\KumaImporter;
use App\Import\Kuma\Source\KumaSource;
use App\Import\Kuma\Source\SourceFactory;
use App\Import\Kuma\Source\UnreadableSource;
use App\Models\Import;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Analyses (dry run) or runs an uploaded Uptime Kuma import from its stored file.
 *
 * An analysis moves the import pending → analyzing → analyzed and keeps the file for the run; a run moves it
 * running → completed. Any failure ends in `failed` with a message that is safe to show, and the file is
 * deleted whenever the import finishes or fails. Exception details go to the log as the class name only:
 * messages of database errors can carry values read from the source.
 */
class RunKumaImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const UNEXPECTED = 'The import failed unexpectedly; see the application log for details.';

    public const TIMED_OUT = 'The import did not finish in time. Large databases can be imported with the command-line importer.';

    public const ACTOR_GONE = 'The user who started this import no longer exists or may no longer manage this workspace.';

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public readonly string $importId, public readonly bool $dryRun)
    {
        // Runs on the connection whose retry_after outlives the timeout (see config/queue.php).
        $this->onConnection('long');
    }

    public function handle(KumaImporter $importer): void
    {
        // Claims the import: a run was already moved to `running` by the request that queued it.
        if ($this->dryRun) {
            $claimed = Import::query()->whereKey($this->importId)->where('status', ImportStatus::Pending)
                ->update(['status' => ImportStatus::Analyzing, 'started_at' => Carbon::now(), 'updated_at' => Carbon::now()]) === 1;
        } else {
            $claimed = Import::query()->whereKey($this->importId)->where('status', ImportStatus::Running)
                ->update(['started_at' => Carbon::now(), 'finished_at' => null, 'updated_at' => Carbon::now()]) === 1;
        }
        $import = $claimed ? Import::query()->with('workspace')->find($this->importId) : null;
        if (! $import instanceof Import) {
            return;
        }

        $workspace = $import->workspace;
        if (! $workspace instanceof Workspace) {
            return;
        }

        // Imports are uploaded through the API and run as their uploader. The user column is cleared when that
        // user is deleted; running as the system instead could do more than the user was allowed to.
        $actor = $import->user_id !== null ? User::query()->find($import->user_id) : null;
        if (! $actor instanceof User || ! $actor->can('update', $workspace)) {
            $this->fail($import, null, self::ACTOR_GONE, [$this->claimedStatus()]);

            return;
        }

        $source = null;
        try {
            if ($import->file_path === null) {
                throw new UnreadableSource('The uploaded file is no longer available. Upload it again.');
            }

            $source = SourceFactory::open(Storage::disk('local')->path($import->file_path), ImportPruner::tempDirectory());
            $options = ImportOptions::fromArray(['dry_run' => $this->dryRun] + $import->options);

            $report = $importer->run($source, $workspace, $options, $actor);
            $source->close();
            $source = null;

            $finished = $this->transition($import, [$this->claimedStatus()], [
                'status' => $this->dryRun ? ImportStatus::Analyzed : ImportStatus::Completed,
                'report' => $report->toArray(),
                'error' => null,
                'finished_at' => Carbon::now(),
            ]);
            if (! $finished) {
                // The pruner gave up on this import meanwhile; its outcome stands.
                Log::warning('Kuma import finished after it was marked failed', ['import_id' => $this->importId, 'dry_run' => $this->dryRun]);

                return;
            }

            if (! $this->dryRun) {
                $this->deleteFile($import);
                Audit::record('import.completed', $import, [
                    'source' => [null, $report->toArray()['source']['kind']],
                    'counts' => [null, $report->counts()],
                ], $actor, $workspace);
            }
        } catch (UnreadableSource $e) {
            $this->fail($import, $source, $e->getMessage(), [$this->claimedStatus()]);
        } catch (Throwable $e) {
            Log::error('Kuma import job failed', ['import_id' => $this->importId, 'dry_run' => $this->dryRun, 'exception' => $e::class]);
            $this->fail($import, $source, self::UNEXPECTED, [$this->claimedStatus()]);
        }
    }

    /**
     * Called by the worker when the job is killed (timeout) or otherwise fails outside handle().
     *
     * A timeout arrives as TimeoutExceededException. A worker that died while running the job (out of memory,
     * killed) leaves it reserved; once it is picked up again it fails with MaxAttemptsExceededException, which
     * is reported as interrupted rather than as too slow.
     */
    public function failed(?Throwable $e): void
    {
        $import = Import::query()->find($this->importId);
        if (! $import instanceof Import || in_array($import->status, [ImportStatus::Completed, ImportStatus::Failed], true)) {
            return;
        }

        // TimeoutExceededException extends MaxAttemptsExceededException, so it is matched first.
        $message = match (true) {
            $e instanceof TimeoutExceededException => self::TIMED_OUT,
            $e instanceof MaxAttemptsExceededException => ImportPruner::INTERRUPTED,
            default => self::UNEXPECTED,
        };
        Log::error('Kuma import job failed', ['import_id' => $this->importId, 'dry_run' => $this->dryRun, 'exception' => $e !== null ? $e::class : null]);
        $this->fail($import, null, $message, [$import->status]);
    }

    /**
     * The state handle() moved the import into before reading the source.
     */
    private function claimedStatus(): ImportStatus
    {
        return $this->dryRun ? ImportStatus::Analyzing : ImportStatus::Running;
    }

    /**
     * @param  list<ImportStatus>  $expected  The states the import may still be in; a failure recorded meanwhile
     *                                        (for example by the pruner) is kept.
     */
    private function fail(Import $import, ?KumaSource $source, string $message, array $expected): void
    {
        try {
            $source?->close();
        } catch (Throwable) {
            // Closing a broken source must not hide the failure being recorded.
        }

        $this->transition($import, $expected, [
            'status' => ImportStatus::Failed,
            'error' => $message,
            'finished_at' => Carbon::now(),
        ]);
        $this->deleteFile($import);
    }

    /**
     * Writes the attributes only while the import is still in one of the expected states, so a concurrent state
     * change (the pruner marking it failed) is never overwritten.
     *
     * @param  list<ImportStatus>  $expected
     * @param  array<string, mixed>  $attributes
     */
    private function transition(Import $import, array $expected, array $attributes): bool
    {
        $import->forceFill($attributes + ['updated_at' => Carbon::now()]);
        $changed = Import::query()->whereKey($import->getKey())->whereIn('status', $expected)
            ->toBase()->update($import->getDirty()) === 1;
        if ($changed) {
            $import->syncOriginal();
        } else {
            $import->refresh();
        }

        return $changed;
    }

    private function deleteFile(Import $import): void
    {
        if ($import->file_path !== null) {
            Storage::disk('local')->delete($import->file_path);
            $import->forceFill(['file_path' => null])->save();
        }
    }
}
