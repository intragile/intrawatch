<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('intrawatch:worker:status {--max-age=60} {--shard=* : Check only these shards} {--local : Check only the shards this container runs}')]
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
        $shards = $this->selectedShards($total);
        if ($shards === null) {
            return self::INVALID;
        }

        $now = CarbonImmutable::now();
        $cutoff = $now->subSeconds((int) $maxAge);

        $seen = [];
        $partitions = [];
        foreach (DB::table('check_workers')->whereIn('shard', $shards)->get(['shard', 'shards', 'last_seen_at']) as $row) {
            $shard = is_numeric($row->shard) ? (int) $row->shard : -1;
            $seen[$shard] = is_string($row->last_seen_at) ? CarbonImmutable::parse($row->last_seen_at) : null;
            $partitions[$shard] = is_numeric($row->shards) ? (int) $row->shards : 0;
        }

        $stale = [];
        foreach ($shards as $shard) {
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

        $count = count($shards);
        $this->info($count === $total
            ? "All {$total} shards reported within {$maxAge} s."
            : 'Shards '.implode(', ', $shards)." of {$total} reported within {$maxAge} s.");

        return self::SUCCESS;
    }

    /**
     * The shards to check: those given with --shard, the local range with --local, or else every shard.
     *
     * @return list<int>|null Null after reporting an invalid selection.
     */
    private function selectedShards(int $total): ?array
    {
        /** @var array<int, mixed> $given */
        $given = (array) $this->option('shard');

        if ($this->option('local')) {
            if ($given !== []) {
                $this->error('--local and --shard cannot be combined.');

                return null;
            }
            $offset = config()->integer('monitoring.worker.shard_offset');
            $workers = config()->integer('monitoring.worker.workers');
            if ($offset < 0 || $workers < 1 || $offset + $workers > $total) {
                $this->error("The local shards {$offset} to ".($offset + $workers - 1)." do not fit into {$total} shards.");

                return null;
            }

            return range($offset, $offset + $workers - 1);
        }

        if ($given === []) {
            return range(0, $total - 1);
        }

        $shards = [];
        foreach ($given as $value) {
            if (! is_string($value) || preg_match('/^\d+$/', $value) !== 1 || (int) $value >= $total) {
                $this->error('--shard must be a shard number between 0 and '.($total - 1).'.');

                return null;
            }
            $shards[(int) $value] = (int) $value;
        }
        ksort($shards);

        return array_values($shards);
    }
}
