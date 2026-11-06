<?php

namespace App\Console\Commands;

use App\Monitoring\Engine\RetentionPruner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('intrawatch:prune')]
#[Description('Delete check results, rollups, events and domain lookups past their retention')]
class PruneCommand extends Command
{
    public function handle(RetentionPruner $pruner): int
    {
        foreach ($pruner->run(CarbonImmutable::now()) as $set => $count) {
            $this->line(sprintf('%s: %d deleted', $set, $count));
        }

        return self::SUCCESS;
    }
}
