<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('intrawatch:worker:status {--max-age=60}')]
#[Description('Exit with 1 when any expected worker shard has not reported within --max-age seconds')]
class WorkerStatusCommand extends Command
{
    public function handle(): int
    {
        $maxAge = $this->option('max-age');
        if (! is_string($maxAge) || preg_match('/^\d+$/', $maxAge) !== 1) {
            $this->error('--max-age must be a number of seconds.');

            return self::INVALID;
        }

        $total = max(1, config()->integer('monitoring.worker.shards_total'));
        $now = CarbonImmutable::now();
        $cutoff = $now->subSeconds((int) $maxAge);

        $seen = [];
        $partitions = [];
        foreach (DB::table('check_workers')->where('shard', '<', $total)->get(['shard', 'shards', 'last_seen_at']) as $row) {
            $shard = is_numeric($row->shard) ? (int) $row->shard : -1;
            $seen[$shard] = is_string($row->last_seen_at) ? CarbonImmutable::parse($row->last_seen_at) : null;
            $partitions[$shard] = is_numeric($row->shards) ? (int) $row->shards : 0;
        }

        $stale = [];
        for ($shard = 0; $shard < $total; $shard++) {
            $lastSeen = $seen[$shard] ?? null;
            if ($lastSeen === null) {
                $stale[] = "Shard {$shard}: never reported";
            } elseif ($partitions[$shard] !== $total) {
                // A worker started with another shard count checks a different set of monitors.
                $stale[] = "Shard {$shard}: runs with {$partitions[$shard]} shards, expected {$total}";
            } elseif ($lastSeen->lt($cutoff)) {
                $stale[] = "Shard {$shard}: last seen ".(int) $lastSeen->diffInSeconds($now).' s ago';
            }
        }

        if ($stale !== []) {
            foreach ($stale as $line) {
                $this->error($line);
            }

            return self::FAILURE;
        }

        $this->info("All {$total} shards reported within {$maxAge} s.");

        return self::SUCCESS;
    }
}
