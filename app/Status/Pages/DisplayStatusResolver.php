<?php

namespace App\Status\Pages;

use App\Enums\DisplayStatus;
use App\Enums\IncidentImpact;
use App\Enums\MonitorStatus;

/**
 * Pure rules that turn engine state, rollup counts and incidents into the public status vocabulary.
 */
final class DisplayStatusResolver
{
    /**
     * The public status of one component.
     *
     * @param  array<string, int>|null  $childCounts  Counted children of a group by monitor status value; null for a non-group.
     * @param  list<IncidentImpact>  $openImpacts  Impacts of the open incidents that name this monitor.
     */
    public static function item(MonitorStatus $status, ?array $childCounts, bool $inMaintenanceWindow, array $openImpacts): DisplayStatus
    {
        $base = match (true) {
            $inMaintenanceWindow, $status === MonitorStatus::Maintenance => DisplayStatus::Maintenance,
            $status === MonitorStatus::Up => DisplayStatus::Operational,
            $status === MonitorStatus::Degraded => DisplayStatus::Degraded,
            $status === MonitorStatus::Down => $childCounts !== null
                && ($childCounts[MonitorStatus::Up->value] ?? 0) + ($childCounts[MonitorStatus::Degraded->value] ?? 0) > 0
                    ? DisplayStatus::PartialOutage
                    : DisplayStatus::MajorOutage,
            default => DisplayStatus::Unknown,
        };

        return DisplayStatus::worst($base, ...array_map(
            static fn (IncidentImpact $impact): DisplayStatus => $impact->displayStatus(),
            $openImpacts,
        ));
    }

    /**
     * The page-wide status: the worst component, ignoring unknown components unless every component is unknown.
     *
     * @param  list<DisplayStatus>  $statuses
     */
    public static function overall(array $statuses): DisplayStatus
    {
        $known = array_values(array_filter($statuses, static fn (DisplayStatus $s): bool => $s !== DisplayStatus::Unknown));
        if ($known === [] && $statuses !== []) {
            return DisplayStatus::Unknown;
        }

        return DisplayStatus::worst(...$known);
    }

    /**
     * Translation key of the page-wide status banner.
     */
    public static function labelKey(DisplayStatus $status): string
    {
        return match ($status) {
            DisplayStatus::Operational => 'all_operational',
            DisplayStatus::Degraded => 'degraded',
            DisplayStatus::PartialOutage => 'partial_outage',
            DisplayStatus::MajorOutage => 'major_outage',
            DisplayStatus::Maintenance => 'maintenance',
            DisplayStatus::Unknown => 'unknown',
        };
    }

    /**
     * The status of one day bar from the day's sample counts.
     */
    public static function day(int $up, int $degraded, int $down, int $maintenance): DisplayStatus
    {
        if ($up + $degraded + $down === 0) {
            return $maintenance > 0 ? DisplayStatus::Maintenance : DisplayStatus::Unknown;
        }
        if ($down === 0) {
            return $degraded === 0 ? DisplayStatus::Operational : DisplayStatus::Degraded;
        }

        return (self::uptimePercent($up + $degraded, $down) ?? 0.0) >= 99.0
            ? DisplayStatus::PartialOutage
            : DisplayStatus::MajorOutage;
    }

    /**
     * Available share of the counted samples in percent with two decimals; null without counted samples. Any
     * downtime keeps the result below 100.
     */
    public static function uptimePercent(int $available, int $down): ?float
    {
        $counted = $available + $down;
        if ($counted <= 0) {
            return null;
        }

        $percent = round($available / $counted * 100, 2);

        return $down > 0 ? min($percent, 99.99) : $percent;
    }
}
