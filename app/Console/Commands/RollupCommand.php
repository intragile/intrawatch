<?php

namespace App\Console\Commands;

use App\Monitoring\Engine\RollupBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('intrawatch:rollup')]
#[Description('Aggregate recent check results into minute, hour and day rollups')]
class RollupCommand extends Command
{
    public function handle(RollupBuilder $builder): int
    {
        $builder->run(CarbonImmutable::now());

        return self::SUCCESS;
    }
}
