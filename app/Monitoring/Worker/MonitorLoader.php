<?php

namespace App\Monitoring\Worker;

use App\Models\Monitor;
use App\Models\MonitorState;
use App\Monitoring\Engine\MonitorSpec;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Loads the active monitors of one shard and detects changes to them by polling.
 *
 * A shard holds the monitors with `id % shards = shard`. The loader remembers what it handed out last, so
 * each poll only fetches full rows for monitors that are new or were modified.
 */
final class MonitorLoader
{
    private const CHUNK = 500;

    /**
     * Rows modified this close to the previous poll are compared field by field, because a second edit in
     * the same second leaves `updated_at` unchanged.
     */
    private const SAME_SECOND_WINDOW = 2;

    /**
     * What was handed out per monitor id. A null spec marks a monitor that failed to load; it is retried
     * once its row is modified.
     *
     * @var array<int, array{updated: ?string, requested: ?string, spec: ?MonitorSpec}>
     */
    private array $known = [];

    /** @var list<int> */
    private array $requestedOnLoad = [];

    private ?CarbonImmutable $lastPoll = null;

    public function __construct(private readonly Clock $clock) {}

    /**
     * @return array<int, MonitorSpec> Active monitors of the shard, keyed by id.
     */
    public function loadShard(int $shard, int $shards): array
    {
        $this->known = [];
        $this->requestedOnLoad = [];
        $this->lastPoll = $this->clock->now();

        $specs = [];
        $this->shardQuery($shard, $shards)
            ->with('state:monitor_id,last_checked_at')
            ->chunkById(self::CHUNK, function ($monitors) use (&$specs): void {
                foreach ($monitors as $monitor) {
                    $spec = $this->spec($monitor);
                    $this->known[$monitor->id] = [
                        'updated' => $this->stamp($monitor->getRawOriginal('updated_at')),
                        'requested' => $this->stamp($monitor->getRawOriginal('check_requested_at')),
                        'spec' => $spec,
                    ];
                    if ($spec === null) {
                        continue;
                    }
                    $specs[$spec->id] = $spec;
                    $state = $monitor->getRelationValue('state');
                    $lastChecked = $state instanceof MonitorState ? $state->last_checked_at : null;
                    if ($monitor->check_requested_at !== null && ($lastChecked === null || $monitor->check_requested_at->gt($lastChecked))) {
                        $this->requestedOnLoad[] = $spec->id;
                    }
                }
            });

        return $specs;
    }

    /**
     * Monitors of the last load whose manual check request is newer than their last check.
     *
     * @return list<int>
     */
    public function requestedOnLoad(): array
    {
        return $this->requestedOnLoad;
    }

    public function changesSince(int $shard, int $shards): ShardChanges
    {
        $pollStartedAt = $this->clock->now();
        $recentSince = ($this->lastPoll ?? $pollStartedAt)->subSeconds(self::SAME_SECOND_WINDOW);

        $seen = [];
        $fetch = [];
        $requested = [];
        foreach ($this->shardRows($shard, $shards) as $row) {
            $id = is_numeric($row->id) ? (int) $row->id : 0;
            $updated = $this->stamp($row->updated_at);
            $requestedAt = $this->stamp($row->check_requested_at);
            $seen[$id] = true;

            $known = $this->known[$id] ?? null;
            if ($known === null || $known['updated'] !== $updated
                || ($updated !== null && CarbonImmutable::parse($updated)->gte($recentSince))) {
                $fetch[] = $id;
            }
            if ($known !== null && $requestedAt !== null && $requestedAt !== $known['requested']) {
                $requested[] = $id;
            }
            if ($known !== null) {
                $this->known[$id]['requested'] = $requestedAt;
            }
        }

        $removed = [];
        foreach (array_keys($this->known) as $id) {
            if (! isset($seen[$id])) {
                $removed[] = $id;
                unset($this->known[$id]);
            }
        }

        $added = [];
        $changed = [];
        foreach (array_chunk($fetch, self::CHUNK) as $ids) {
            foreach ($this->shardQuery($shard, $shards)->whereIn('id', $ids)->get() as $monitor) {
                $spec = $this->spec($monitor);
                $previous = $this->known[$monitor->id] ?? null;
                $this->known[$monitor->id] = [
                    'updated' => $this->stamp($monitor->getRawOriginal('updated_at')),
                    'requested' => $this->stamp($monitor->getRawOriginal('check_requested_at')),
                    'spec' => $spec,
                ];

                if ($spec === null) {
                    if ($previous !== null && $previous['spec'] !== null) {
                        $removed[] = $monitor->id;
                    }

                    continue;
                }
                if ($previous === null || $previous['spec'] === null) {
                    $added[$spec->id] = $spec;
                    // A request made together with the activation is honoured by the immediate first check.
                } elseif ($previous['spec'] != $spec) {
                    $changed[$spec->id] = $spec;
                }
            }
        }

        $this->lastPoll = $pollStartedAt;

        return new ShardChanges(
            $added,
            $changed,
            $removed,
            array_values(array_filter($requested, fn (int $id): bool => ($this->known[$id]['spec'] ?? null) !== null && ! isset($added[$id]))),
        );
    }

    /**
     * @return Builder<Monitor>
     */
    private function shardQuery(int $shard, int $shards): Builder
    {
        return Monitor::query()
            ->where('active', true)
            ->whereRaw('id % ? = ?', [$shards, $shard]);
    }

    /**
     * @return iterable<object{id: mixed, updated_at: mixed, check_requested_at: mixed}>
     */
    private function shardRows(int $shard, int $shards): iterable
    {
        /** @var iterable<object{id: mixed, updated_at: mixed, check_requested_at: mixed}> */
        return DB::table('monitors')
            ->where('active', true)
            ->whereRaw('id % ? = ?', [$shards, $shard])
            ->orderBy('id')
            ->get(['id', 'updated_at', 'check_requested_at']);
    }

    private function spec(Monitor $monitor): ?MonitorSpec
    {
        try {
            return MonitorSpec::fromModel($monitor);
        } catch (Throwable $e) {
            // For instance secrets that no longer decrypt after an APP_KEY change.
            Log::error('A monitor could not be loaded and is not checked.', [
                'monitor_id' => $monitor->id,
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    private function stamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
