<?php

namespace App\Console\Commands;

use App\Status\Maintenance\MaintenanceSync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('intrawatch:maintenance:sync')]
#[Description('Check scheduled monitors and recompute groups whose maintenance window started or ended')]
class MaintenanceSyncCommand extends Command
{
    private const WATERMARK_KEY = 'maintenance:sync:at';

    private const DEFAULT_LOOKBACK_SECONDS = 60;

    private const MAX_LOOKBACK_SECONDS = 3600;

    public function handle(MaintenanceSync $sync): int
    {
        $now = CarbonImmutable::now();
        $stored = Cache::get(self::WATERMARK_KEY);
        $from = is_int($stored) ? CarbonImmutable::createFromTimestampUTC($stored) : $now->subSeconds(self::DEFAULT_LOOKBACK_SECONDS);
        $earliest = $now->subSeconds(self::MAX_LOOKBACK_SECONDS);
        if ($from->lt($earliest)) {
            $from = $earliest;
        }

        $sync->run($from, $now);
        // Only a completed run moves the watermark, so a failed one is retried over the same range.
        Cache::forever(self::WATERMARK_KEY, $now->getTimestamp());

        return self::SUCCESS;
    }
}
