<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\ImportStatus;
use App\Enums\WorkspaceRole;
use App\Import\Kuma\ImportContext;
use App\Import\Kuma\KumaImporter;
use App\Import\Kuma\Steps\ImportStep;
use App\Jobs\RunKumaImport;
use App\Models\Import;
use App\Models\Monitor;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PDO;
use Tests\Feature\Import\Kuma\ImporterTest;
use Tests\TestCase;

final class RunKumaImportTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->ws = Workspace::factory()->create();
    }

    private function stored(ImportStatus $status, string $contents, ?Carbon $createdAt = null): Import
    {
        $import = new Import([
            'workspace_id' => $this->ws->id, 'user_id' => User::factory()->memberOf($this->ws, WorkspaceRole::Admin)->create()->id, 'source' => 'uptime_kuma', 'status' => $status,
            'options' => ['history' => true, 'paused' => false], 'file_name' => 'kuma.db', 'file_size' => strlen($contents),
        ]);
        $import->save();
        $import->file_path = 'imports/'.$import->id;
        if ($createdAt !== null) {
            $import->created_at = $createdAt;
        }
        $import->save();
        Storage::disk('local')->put($import->file_path, $contents);

        return $import;
    }

    private function handle(RunKumaImport $job): void
    {
        app()->call([$job, 'handle']);
    }

    public function test_failed_job_marks_failed_and_deletes_file(): void
    {
        $step = new class implements ImportStep
        {
            public function run(ImportContext $ctx): void
            {
                throw new \RuntimeException('SQLSTATE[HY000] S3cr3t-binding /var/lib/secret/path');
            }
        };
        $this->app->instance($step::class, $step);
        $this->app->bind(KumaImporter::class, fn () => new KumaImporter([$step::class]));
        $logged = [];
        Log::listen(function (MessageLogged $m) use (&$logged): void {
            $logged[] = $m->message.json_encode($m->context);
        });
        $import = $this->stored(ImportStatus::Pending, (string) file_get_contents(ImporterTest::fixture('kuma2.db')));

        $this->handle(new RunKumaImport($import->id, dryRun: true));

        $import->refresh();
        $this->assertSame(ImportStatus::Failed, $import->status);
        $this->assertSame('The import failed unexpectedly; see the application log for details.', $import->error);
        $this->assertNull($import->file_path);
        $this->assertNotNull($import->finished_at);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $logs = (string) json_encode($logged);
        $this->assertStringNotContainsString('S3cr3t', $logs.$import->error);
        $this->assertStringContainsString('RuntimeException', $logs);
    }

    public function test_unreadable_source_fails_with_its_user_safe_message(): void
    {
        // A valid SQLite file that is not a Kuma database.
        $path = (string) tempnam(sys_get_temp_dir(), 'not-kuma-');
        $pdo = new PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');
        $pdo = null;
        $import = $this->stored(ImportStatus::Pending, (string) file_get_contents($path));
        @unlink($path);

        $this->handle(new RunKumaImport($import->id, dryRun: true));

        $import->refresh();
        $this->assertSame(ImportStatus::Failed, $import->status);
        $this->assertIsString($import->error);
        $this->assertStringNotContainsString('SQLSTATE', $import->error);
        $this->assertStringNotContainsString('unexpectedly', $import->error);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_failed_handler_covers_timeouts(): void
    {
        $import = $this->stored(ImportStatus::Running, 'SQLite format 3');

        (new RunKumaImport($import->id, dryRun: false))->failed(new TimeoutExceededException('timed out'));

        $import->refresh();
        $this->assertSame(ImportStatus::Failed, $import->status);
        $this->assertSame('The import did not finish in time. Large databases can be imported with the command-line importer.', $import->error);
        $this->assertNull($import->file_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_job_ignores_imports_in_an_unexpected_state(): void
    {
        $import = $this->stored(ImportStatus::Analyzed, 'SQLite format 3');

        $this->handle(new RunKumaImport($import->id, dryRun: true));
        $this->handle(new RunKumaImport('01jzzzzzzzzzzzzzzzzzzzzzzz', dryRun: true));

        $this->assertSame(ImportStatus::Analyzed, $import->refresh()->status);
        Storage::disk('local')->assertExists('imports/'.$import->id);
    }

    public function test_run_fails_when_the_starting_user_is_gone_or_demoted(): void
    {
        $kuma = (string) file_get_contents(ImporterTest::fixture('kuma2.db'));
        $deleted = $this->stored(ImportStatus::Running, $kuma);
        User::findOrFail($deleted->user_id)->delete();
        $demoted = $this->stored(ImportStatus::Running, $kuma);
        User::findOrFail($demoted->user_id)->workspaces()->updateExistingPivot($this->ws->id, ['role' => WorkspaceRole::Editor->value]);

        foreach ([$deleted, $demoted] as $import) {
            $this->handle(new RunKumaImport($import->id, dryRun: false));

            $import->refresh();
            $this->assertSame(ImportStatus::Failed, $import->status);
            $this->assertSame(RunKumaImport::ACTOR_GONE, $import->error);
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, Monitor::count());
    }

    public function test_job_runs_on_the_long_queue_once(): void
    {
        $job = new RunKumaImport('01jzzzzzzzzzzzzzzzzzzzzzzz', dryRun: false);

        $this->assertSame('long', $job->connection);
        $this->assertSame(900, $job->timeout);
        $this->assertSame(1, $job->tries);
    }

    public function test_prune_removes_stale_files_and_marks_interrupted(): void
    {
        $now = Carbon::now();
        $kuma = (string) file_get_contents(ImporterTest::fixture('kuma2.db'));
        $staleRunning = $this->stored(ImportStatus::Running, $kuma, $now->copy()->subHours(3));
        $staleAnalyzing = $this->stored(ImportStatus::Analyzing, $kuma, $now->copy()->subHours(3));
        $stalePending = $this->stored(ImportStatus::Pending, $kuma, $now->copy()->subHours(3));
        $freshRunning = $this->stored(ImportStatus::Running, $kuma, $now->copy()->subHour());
        $expiredAnalyzed = $this->stored(ImportStatus::Analyzed, $kuma, $now->copy()->subHours(25));
        $freshAnalyzed = $this->stored(ImportStatus::Analyzed, $kuma, $now->copy()->subHours(5));
        // A stale import has not changed state for over two hours.
        foreach ([$staleRunning, $staleAnalyzing, $stalePending] as $import) {
            DB::table('imports')->where('id', $import->id)->update(['updated_at' => $now->copy()->subHours(3)]);
        }
        DB::table('imports')->where('id', $freshRunning->id)->update(['updated_at' => $now->copy()->subMinutes(10)]);
        // A file whose row is gone, for example after its workspace was deleted.
        Storage::disk('local')->put('imports/orphan', 'x');
        touch(Storage::disk('local')->path('imports/orphan'), $now->copy()->subHours(25)->getTimestamp());

        $this->assertSame(0, Artisan::call('intrawatch:prune'));
        $output = Artisan::output();
        $this->assertStringContainsString('import_files: 5 deleted', $output);
        $this->assertStringContainsString('interrupted_imports: 3 marked failed', $output);

        foreach ([$staleRunning, $staleAnalyzing, $stalePending] as $import) {
            $import->refresh();
            $this->assertSame(ImportStatus::Failed, $import->status);
            $this->assertSame('interrupted', $import->error);
            $this->assertNull($import->file_path);
            Storage::disk('local')->assertMissing('imports/'.$import->id);
        }

        $this->assertSame(ImportStatus::Running, $freshRunning->refresh()->status);
        Storage::disk('local')->assertExists('imports/'.$freshRunning->id);
        $this->assertSame(ImportStatus::Analyzed, $freshAnalyzed->refresh()->status);
        Storage::disk('local')->assertExists('imports/'.$freshAnalyzed->id);

        $expiredAnalyzed->refresh();
        $this->assertSame(ImportStatus::Failed, $expiredAnalyzed->status);
        $this->assertSame('The uploaded file expired before the import was run. Upload it again.', $expiredAnalyzed->error);
        $this->assertNull($expiredAnalyzed->file_path);
        Storage::disk('local')->assertMissing('imports/orphan');
    }
}
