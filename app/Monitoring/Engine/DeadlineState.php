<?php

namespace App\Monitoring\Engine;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use Carbon\CarbonImmutable;

/**
 * The part of a monitor_states row that push and cron deadlines are computed from. Both the evaluator and
 * the recorder read it through this class, so they agree on where a deadline counts from.
 */
final readonly class DeadlineState
{
    /**
     * Columns to select for fromRow().
     */
    public const COLUMNS = ['status', 'status_since', 'last_checked_at', 'last_ping_at', 'run_started_at', 'deadline_anchor_at'];

    public function __construct(
        public MonitorStatus $status,
        public CarbonImmutable $statusSince,
        public ?CarbonImmutable $lastCheckedAt,
        public ?CarbonImmutable $lastPingAt,
        public ?CarbonImmutable $runStartedAt,
        public ?CarbonImmutable $deadlineAnchorAt,
    ) {}

    public static function fromRow(object $row): self
    {
        return new self(
            MonitorStatus::tryFrom(self::string($row->status ?? null) ?? '') ?? MonitorStatus::Pending,
            self::time($row->status_since ?? null) ?? CarbonImmutable::now(),
            self::time($row->last_checked_at ?? null),
            self::time($row->last_ping_at ?? null),
            self::time($row->run_started_at ?? null),
            self::time($row->deadline_anchor_at ?? null),
        );
    }

    /**
     * Where the current deadline counts from.
     *
     * Push: the later of the stored anchor and the last heartbeat. Cron: the later of the stored anchor (the
     * last completed run) and the start of a run in progress, because a started run is the expected run.
     */
    public function anchor(MonitorType $type): CarbonImmutable
    {
        $candidates = array_filter([
            $this->deadlineAnchorAt,
            $type === MonitorType::Cron ? $this->runStartedAt : $this->lastPingAt,
        ]);
        if ($candidates === []) {
            return $this->lastPingAt ?? $this->statusSince;
        }

        return max($candidates);
    }

    /**
     * Whether a down for this deadline has been recorded already.
     */
    public function reported(CarbonImmutable $deadline): bool
    {
        return $this->status === MonitorStatus::Down && $this->lastCheckedAt !== null && $this->lastCheckedAt->gte($deadline);
    }

    /**
     * The same state after a result with the given status was recorded at $checkedAt.
     */
    public function withCheck(MonitorStatus $status, CarbonImmutable $checkedAt): self
    {
        return new self($status, $this->statusSince, $checkedAt, $this->lastPingAt, $this->runStartedAt, $this->deadlineAnchorAt);
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        $value = self::string($value);

        return $value === null || $value === '' ? null : CarbonImmutable::parse($value);
    }

    private static function string(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
