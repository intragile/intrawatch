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
            AlertKind::Stable => $item->status === 'down',
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
