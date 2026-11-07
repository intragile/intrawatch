<?php

namespace App\Console\Commands;

use App\Enums\ImportOutcome;
use App\Import\Kuma\ImportOptions;
use App\Import\Kuma\ImportReport;
use App\Import\Kuma\KumaImporter;
use App\Import\Kuma\Source\KumaSource;
use App\Import\Kuma\Source\SourceFactory;
use App\Import\Kuma\Source\UnreadableSource;
use App\Models\Workspace;
use App\Support\Audit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('intrawatch:import:kuma {source : Path to a Kuma SQLite database or 1.23 JSON backup, or a mysql:// DSN} {--workspace= : Slug of the target workspace} {--dry-run : Analyse only; write nothing} {--no-history : Skip heartbeat history} {--paused : Import monitors paused} {--report= : Write the JSON report to this path}')]
#[Description('Import monitors, channels, tags and more from an Uptime Kuma instance into a workspace')]
class ImportKumaCommand extends Command
{
    public function handle(KumaImporter $importer): int
    {
        $slug = trim((string) $this->option('workspace'));
        $workspace = $slug === '' ? null : Workspace::where('slug', $slug)->first();
        if ($workspace === null) {
            $this->error($slug === '' ? 'Pass the target workspace with --workspace=<slug>.' : "No workspace with the slug {$slug}.");

            return self::FAILURE;
        }

        $source = null;
        try {
            $source = SourceFactory::open((string) $this->argument('source'));
            $options = new ImportOptions(
                dryRun: (bool) $this->option('dry-run'),
                history: ! $this->option('no-history'),
                paused: (bool) $this->option('paused'),
            );

            return $this->runImport($importer, $source, $workspace, $options);
        } catch (UnreadableSource $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            report($e);
            $this->error('The import failed unexpectedly; see the application log for details.');

            return 2;
        } finally {
            $source?->close();
        }
    }

    private function runImport(KumaImporter $importer, KumaSource $source, Workspace $workspace, ImportOptions $options): int
    {
        $report = $importer->run($source, $workspace, $options, null);

        $path = $this->option('report');
        if (is_string($path) && $path !== '') {
            $json = json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (@file_put_contents($path, $json."\n") === false) {
                $this->error("The report could not be written to {$path}.");

                return 2;
            }
        }

        $this->printReport($report, $options->dryRun);

        if (! $options->dryRun) {
            Audit::record('import.completed', null, [
                'source' => [null, $source->kind()->value],
                'counts' => [null, $report->counts()],
            ], null, $workspace);
        }

        return self::SUCCESS;
    }

    private function printReport(ImportReport $report, bool $dryRun): void
    {
        $this->line($dryRun ? 'Dry run: nothing was written.' : 'Import finished.');

        $outcomes = array_map(static fn (ImportOutcome $o): string => $o->value, ImportOutcome::cases());
        $rows = [];
        foreach ($report->counts() as $entity => $counts) {
            $rows[] = [$entity, ...array_map(static fn (string $o): int => $counts[$o] ?? 0, $outcomes)];
        }
        $this->table(['entity', ...$outcomes], $rows);

        $this->line('Not migrated:');
        foreach ($report->notMigrated() as $what => $count) {
            $this->line('  '.str_replace('_', ' ', $what).': '.$count);
        }

        foreach ($report->notes() as $note) {
            $this->warn($note['message']);
        }
    }
}
