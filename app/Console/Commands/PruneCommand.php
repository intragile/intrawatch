<?php

namespace App\Console\Commands;

use App\Import\ImportPruner;
use App\Monitoring\Engine\RetentionPruner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('intrawatch:prune')]
#[Description('Delete check results, rollups, events and domain lookups past their retention, and stale import files')]
class PruneCommand extends Command
{
    public function handle(RetentionPruner $pruner, ImportPruner $imports): int
    {
        $now = CarbonImmutable::now();
        foreach ($pruner->run($now) as $set => $count) {
            $this->line(sprintf('%s: %d deleted', $set, $count));
        }

        $result = $imports->run($now);
        $this->line(sprintf('import_files: %d deleted', $result['import_files']));
        $this->line(sprintf('interrupted_imports: %d marked failed', $result['interrupted_imports']));

        return self::SUCCESS;
    }
}
