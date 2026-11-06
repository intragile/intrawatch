<?php

namespace App\Monitoring\Engine;

use App\Enums\MonitorStatus;
use App\Models\MonitorState;
use Carbon\CarbonImmutable;

/**
 * The persisted runtime state of a monitor, as the status machine sees it.
 */
final readonly class StateSnapshot
{
    /**
     * @param  array<string, string>  $watch  Last observed value of each watched key.
     */
    public function __construct(
        public MonitorStatus $status,
        public int $consecutiveFailures,
        public CarbonImmutable $since,
        public array $watch = [],
    ) {}

    /**
     * @param  array<string, string>  $watch
     */
    public static function of(MonitorStatus $status, int $consecutiveFailures, ?CarbonImmutable $since = null, array $watch = []): self
    {
        return new self($status, $consecutiveFailures, $since ?? CarbonImmutable::now(), $watch);
    }

    public static function fromModel(MonitorState $state): self
    {
        return new self(
            $state->status,
            $state->consecutive_failures,
            CarbonImmutable::instance($state->status_since),
            self::normalizeWatch($state->watch),
        );
    }

    /**
     * @return array<string, string>
     */
    public static function normalizeWatch(mixed $watch): array
    {
        if (! is_array($watch)) {
            return [];
        }

        $normalized = [];
        foreach ($watch as $key => $value) {
            if (is_scalar($value)) {
                $normalized[(string) $key] = (string) $value;
            }
        }

        return $normalized;
    }
}
