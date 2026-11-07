<?php

namespace App\Status\Maintenance;

use App\Monitoring\Engine\MaintenanceOracle;
use App\Monitoring\Worker\Clock;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Answers maintenance questions from an in-memory calendar.
 *
 * underMaintenance() never touches the database. warm() keeps the calendar current; it reads the database
 * and must run outside transactions. A process that never warmed (a short-lived web request) loads the
 * calendar on first use instead.
 */
final class CalendarMaintenanceOracle implements MaintenanceOracle
{
    private const WARNING_INTERVAL_SECONDS = 60;

    private const RELOAD_BEFORE_HORIZON_END_SECONDS = 86400;

    private ?MaintenanceCalendar $calendar = null;

    private ?string $stamp = null;

    private ?CarbonImmutable $loadedAt = null;

    private ?CarbonImmutable $checkedAt = null;

    private ?CarbonImmutable $warnedAt = null;

    private bool $attempted = false;

    private bool $horizonLogged = false;

    public function __construct(
        private readonly MaintenanceCalendarLoader $loader,
        private readonly Clock $clock,
        private readonly int $stampSeconds = 5,
        private readonly int $reloadSeconds = 60,
        private readonly ?Closure $transactionLevel = null,
    ) {}

    public function warm(): void
    {
        $this->attempted = true;
        $now = $this->clock->now();

        if ($this->calendar !== null && $this->checkedAt !== null && $now->diffInSeconds($this->checkedAt, true) < $this->stampSeconds) {
            return;
        }
        $this->checkedAt = $now;

        try {
            $stamp = $this->loader->stamp();
            if ($this->calendar === null || $this->needsReload($stamp, $now)) {
                $this->calendar = $this->loader->load($now);
                $this->stamp = $stamp;
                $this->loadedAt = $now;
            }
        } catch (Throwable $e) {
            if ($this->warnedAt === null || $now->diffInSeconds($this->warnedAt, true) >= self::WARNING_INTERVAL_SECONDS) {
                $this->warnedAt = $now;
                Log::warning('Loading the maintenance calendar failed; keeping the previous one.', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Reloads the calendar now, ignoring the check interval. Unlike warm() it may run inside the transaction that
     * has just changed a maintenance, so the reload sees that transaction's own writes.
     */
    public function refresh(): void
    {
        $this->attempted = true;
        $now = $this->clock->now();
        // A failure propagates: the caller's write must not commit a status derived from an outdated calendar.
        $stamp = $this->loader->stamp();
        $this->calendar = $this->loader->load($now);
        $this->stamp = $stamp;
        $this->loadedAt = $now;
        $this->checkedAt = $now;
    }

    /**
     * Forgets the loaded calendar, so the next use loads the committed state again.
     */
    public function invalidate(): void
    {
        $this->calendar = null;
        $this->stamp = null;
        $this->checkedAt = null;
        $this->loadedAt = null;
        $this->attempted = false;
    }

    /**
     * @return list<int> Monitors with a window start or end in `(from, to]` in the loaded calendar.
     */
    public function monitorsWithBoundaryIn(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->calendar?->monitorsWithBoundaryIn($from, $to) ?? [];
    }

    public function underMaintenance(array $monitorIds, CarbonImmutable $at): array
    {
        if ($this->calendar === null) {
            // First use in a process that never warmed. Accepted even inside a transaction.
            if (! $this->attempted) {
                $this->warm();
            }
        } elseif ($this->isStale($this->clock->now()) && $this->outsideTransaction()) {
            // A long-lived process that nobody warms refreshes itself, but never inside a transaction.
            $this->warm();
        }
        if ($this->calendar === null) {
            return [];
        }
        if (! $this->calendar->covers($at)) {
            if (! $this->horizonLogged) {
                $this->horizonLogged = true;
                Log::warning('A maintenance question fell outside the calendar horizon; answering that no monitor is under maintenance.', [
                    'at' => $at->toIso8601ZuluString(),
                    'from' => $this->calendar->from->toIso8601ZuluString(),
                    'to' => $this->calendar->to->toIso8601ZuluString(),
                ]);
            }

            return [];
        }

        return $this->calendar->underMaintenance($monitorIds, $at);
    }

    private function needsReload(string $stamp, CarbonImmutable $now): bool
    {
        return $stamp !== $this->stamp || $this->isStale($now);
    }

    /**
     * Whether the calendar is older than the forced reload interval or the moment is near or past its horizon.
     */
    private function isStale(CarbonImmutable $now): bool
    {
        if ($this->calendar === null || $this->loadedAt === null) {
            return true;
        }

        return $now->diffInSeconds($this->loadedAt, true) >= $this->reloadSeconds
            || $now->getTimestamp() >= $this->calendar->to->getTimestamp() - self::RELOAD_BEFORE_HORIZON_END_SECONDS;
    }

    private function outsideTransaction(): bool
    {
        return ($this->transactionLevel === null ? DB::transactionLevel() : ($this->transactionLevel)()) === 0;
    }
}
