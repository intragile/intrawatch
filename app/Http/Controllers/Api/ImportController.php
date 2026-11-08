<?php

namespace App\Http\Controllers\Api;

use App\Enums\ImportStatus;
use App\Exceptions\ImportConflict;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPage;
use App\Http\Requests\StoreImportRequest;
use App\Http\Resources\ImportPresenter;
use App\Import\Kuma\Source\SourceFactory;
use App\Import\Kuma\Source\UnreadableSource;
use App\Jobs\RunKumaImport;
use App\Models\Import;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Imports from Uptime Kuma: an upload is analysed (a dry run) on the long queue, and an analysed import is
 * run from the same stored file. A workspace has at most one unfinished import at a time.
 */
class ImportController extends Controller
{
    public const SOURCE = 'uptime_kuma';

    /** Statuses in which an import still holds its workspace's single import slot. */
    private const UNFINISHED = [ImportStatus::Pending, ImportStatus::Analyzing, ImportStatus::Analyzed, ImportStatus::Running];

    /** Statuses in which a queued job is working on the import. */
    private const BUSY = [ImportStatus::Analyzing, ImportStatus::Running];

    /**
     * List the workspace's imports, newest first.
     */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        return CursorPage::from(
            $workspace->imports()->orderByDesc('id'),
            $request,
            fn (Model $m): array => ImportPresenter::toArray($m instanceof Import ? $m : throw new \LogicException('Expected an import.')),
        );
    }

    /**
     * Upload an Uptime Kuma SQLite database or 1.23 JSON backup and queue its analysis.
     *
     * The file must start like a SQLite database or a JSON document; anything else is a 422 on `file`. A
     * workspace whose previous import has not finished (or was analysed but neither run nor deleted) gets a
     * 409 problem of type `urn:problem:import-in-progress`.
     *
     * @status 202
     */
    public function store(StoreImportRequest $request, Workspace $workspace): JsonResponse
    {
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => ['The file field is required.']]);
        }
        $user = $request->user();

        $this->ensureNoUnfinishedImport($workspace);

        $id = strtolower((string) Str::ulid());
        $path = 'imports/'.$id;
        if (Storage::disk('local')->putFileAs('imports', $file, $id) === false) {
            throw new \RuntimeException('The uploaded file could not be stored.');
        }

        try {
            SourceFactory::detect(Storage::disk('local')->path($path));
        } catch (UnreadableSource $e) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        }

        try {
            $import = DB::transaction(function () use ($workspace, $user, $file, $request, $id, $path): Import {
                // Serialises concurrent uploads to one workspace, so only one of them takes the import slot.
                Workspace::query()->whereKey($workspace->id)->lockForUpdate()->first();
                $this->ensureNoUnfinishedImport($workspace);

                $import = new Import([
                    'workspace_id' => $workspace->id,
                    'user_id' => $user instanceof User ? $user->id : null,
                    'source' => self::SOURCE,
                    'status' => ImportStatus::Pending,
                    'options' => ['history' => $request->boolean('history', true), 'paused' => $request->boolean('paused', false)],
                    'file_path' => $path,
                    'file_name' => self::fileName($file),
                    'file_size' => (int) $file->getSize(),
                ]);
                $import->id = $id;
                $import->save();

                return $import;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        RunKumaImport::dispatch($import->id, true);

        return response()->json(['data' => ImportPresenter::toArray($import->refresh())], 202);
    }

    /**
     * Show an import with its report.
     */
    public function show(Workspace $workspace, Import $import): JsonResponse
    {
        // refresh() gives Scramble the same model type as store/run, so the documented shape is identical.
        return response()->json(['data' => ImportPresenter::toArray($import->refresh())]);
    }

    /**
     * Run an analysed import.
     *
     * Only an import in status `analyzed` can be run; any other status is a 409 problem of type
     * `urn:problem:import-not-analyzed`.
     *
     * @status 202
     */
    public function run(Workspace $workspace, Import $import): JsonResponse
    {
        $claimed = Import::query()->whereKey($import->id)->where('status', ImportStatus::Analyzed)
            ->update(['status' => ImportStatus::Running, 'updated_at' => now()]);
        if ($claimed !== 1) {
            throw ImportConflict::notAnalyzed();
        }

        RunKumaImport::dispatch($import->id, false);

        return response()->json(['data' => ImportPresenter::toArray($import->refresh())], 202);
    }

    /**
     * Delete an import and its stored file.
     *
     * An import that is being analysed or run cannot be deleted (409 problem of type
     * `urn:problem:import-in-progress`). The entities a completed run created stay.
     */
    public function destroy(Workspace $workspace, Import $import): Response
    {
        if (in_array($import->status, self::BUSY, true)) {
            throw ImportConflict::busy();
        }

        if ($import->file_path !== null) {
            Storage::disk('local')->delete($import->file_path);
        }
        $import->delete();

        return response()->noContent();
    }

    private function ensureNoUnfinishedImport(Workspace $workspace): void
    {
        if ($workspace->imports()->whereIn('status', self::UNFINISHED)->exists()) {
            throw ImportConflict::inProgress();
        }
    }

    /**
     * The client's file name without any directory part or control characters, for display only.
     */
    private static function fileName(UploadedFile $file): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName())));

        return Str::limit($name === '' ? 'upload' : $name, 250, '');
    }
}
