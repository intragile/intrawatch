<?php

namespace App\Status\Maintenance;

use App\Monitoring\Engine\MaintenanceOracle;
use App\Monitoring\Worker\Clock;
use Carbon\CarbonImmutable;
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

    public function underMaintenance(array $monitorIds, CarbonImmutable $at): array
    {
        if ($this->calendar === null && ! $this->attempted) {
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
        if ($stamp !== $this->stamp || $this->calendar === null || $this->loadedAt === null) {
            return true;
        }

        return $now->diffInSeconds($this->loadedAt, true) >= $this->reloadSeconds
            || $now->getTimestamp() >= $this->calendar->to->getTimestamp() - self::RELOAD_BEFORE_HORIZON_END_SECONDS;
    }
}
