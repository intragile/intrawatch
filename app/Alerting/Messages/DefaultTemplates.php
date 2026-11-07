<?php

namespace App\Alerting\Messages;

use App\Enums\AlertKind;

/**
 * Built-in notification copy, used when a channel has no template of its own.
 */
final class DefaultTemplates
{
    public static function title(): string
    {
        return '[{{ kind_label }}] {{ monitor.name }}';
    }

    public static function body(AlertKind $kind): string
    {
        $text = match ($kind) {
            AlertKind::Down => '{{ monitor.name }} ({{ monitor.target }}) is down: {{ message }}',
            AlertKind::Up => '{{ monitor.name }} ({{ monitor.target }}) is up again after {{ duration }}.',
            AlertKind::Degraded => '{{ monitor.name }} ({{ monitor.target }}) is degraded: {{ message }}',
            AlertKind::StillDown => '{{ monitor.name }} ({{ monitor.target }}) has been down for {{ duration }}: {{ message }}',
            AlertKind::Flapping => '{{ monitor.name }} ({{ monitor.target }}) is flapping and is now {{ status }}. Alerts are paused until it is stable.',
            AlertKind::Stable => '{{ monitor.name }} ({{ monitor.target }}) is stable again and is now {{ status }}.',
            AlertKind::Expiring, AlertKind::Renewed, AlertKind::Changed => "{{ monitor.name }}: {{ message }}\n{{ changes }}",
            AlertKind::Test => 'This is a test notification from IntraWatch.',
        };

        return $text."\n{{ monitor.url }}";
    }
}
