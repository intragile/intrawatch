<?php

namespace App\Alerting\Channels\Senders;

use App\Alerting\Messages\AlertMessage;
use App\Enums\AlertKind;

/**
 * Shared alert identity and trigger/resolve decision for incident tools that deduplicate per monitor.
 */
abstract class IncidentToolSender extends PushServiceSender
{
    /**
     * Whether the alert opens an incident (true) or closes one (false).
     */
    protected static function opens(AlertMessage $item): bool
    {
        return match ($item->kind) {
            AlertKind::Down, AlertKind::StillDown, AlertKind::Flapping, AlertKind::Degraded,
            AlertKind::Expiring, AlertKind::Changed, AlertKind::Test => true,
            AlertKind::Up, AlertKind::Renewed => false,
            // Flapping that settles at a problem leaves the incident open at that problem's severity.
            AlertKind::Stable => in_array($item->status, ['down', 'degraded'], true),
        };
    }

    /**
     * The alert's severity; for a settled flap, the severity of the status it settled at.
     */
    protected static function severity(AlertMessage $item): string
    {
        if ($item->kind !== AlertKind::Stable) {
            return $item->severity;
        }

        return match ($item->status) {
            'down' => AlertKind::Down->severity(),
            'degraded' => AlertKind::Degraded->severity(),
            default => $item->severity,
        };
    }

    /**
     * Stable identity of the incident an alert belongs to. Change alerts are keyed per changed property so a
     * renewal closes exactly the expiry it follows.
     */
    protected static function incidentKey(AlertMessage $item): string
    {
        $key = "intrawatch-{$item->workspaceId}-".($item->monitorId ?? 0);
        if (in_array($item->kind, [AlertKind::Expiring, AlertKind::Changed, AlertKind::Renewed], true) && $item->changes !== []) {
            $key .= '-'.$item->changes[0]['key'];
        }

        return $key;
    }
}
