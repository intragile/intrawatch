<?php

namespace App\Alerting\Messages;

use App\Enums\AlertKind;
use App\Models\AlertEvent;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/**
 * Builds the notification a channel delivers for a set of alert events.
 */
final class MessageFactory
{
    private const DIGEST_LINES = 20;

    private const SEVERITY_ORDER = ['critical' => 3, 'warning' => 2, 'ok' => 1, 'info' => 0];

    /**
     * @param  list<AlertEvent>  $events
     */
    public function forEvents(NotificationChannel $channel, array $events): OutgoingNotification
    {
        if ($events === []) {
            throw new \InvalidArgumentException('At least one alert event is required.');
        }

        $workspace = Workspace::query()->findOrFail($channel->workspace_id);

        $monitorIds = array_values(array_unique(array_filter(array_map(
            static fn (AlertEvent $e): ?int => $e->monitor_id,
            $events,
        ))));
        $monitors = [];
        if ($monitorIds !== []) {
            foreach (Monitor::query()->whereIn('id', $monitorIds)->get() as $monitor) {
                $monitors[$monitor->id] = $monitor;
            }
        }

        $items = [];
        foreach ($events as $event) {
            $monitor = $event->monitor_id === null ? null : ($monitors[$event->monitor_id] ?? null);
            $items[] = $this->message($channel, $workspace, $event, $monitor);
        }

        if (count($items) === 1) {
            return new OutgoingNotification($items[0]->title, $items[0]->body, $items[0]->severity, $items, false);
        }

        return new OutgoingNotification(
            count($items).' alerts in '.$workspace->name,
            $this->digestBody($items),
            $this->mostSevere($items),
            $items,
            true,
        );
    }

    public function test(NotificationChannel $channel): OutgoingNotification
    {
        $workspace = Workspace::query()->findOrFail($channel->workspace_id);

        $item = $this->build(
            $channel,
            $workspace,
            AlertKind::Test,
            null,
            'IntraWatch test',
            '-',
            '-',
            null,
            null,
            'Test notification',
            CarbonImmutable::now(),
            null,
            [],
        );

        return new OutgoingNotification($item->title, $item->body, $item->severity, [$item], false);
    }

    private function message(NotificationChannel $channel, Workspace $workspace, AlertEvent $event, ?Monitor $monitor): AlertMessage
    {
        return $this->build(
            $channel,
            $workspace,
            $event->kind,
            $event->monitor_id,
            $monitor === null ? 'Deleted monitor' : $monitor->name,
            $monitor === null ? '-' : MonitorTarget::describe($monitor),
            $monitor === null ? '-' : $monitor->type->value,
            $event->status?->value,
            $event->previous_status?->value,
            $event->message,
            CarbonImmutable::instance($event->created_at),
            $event->duration_seconds,
            $event->changes ?? [],
        );
    }

    /**
     * @param  list<array{key: string, old: mixed, new: mixed}>  $changes
     */
    private function build(
        NotificationChannel $channel,
        Workspace $workspace,
        AlertKind $kind,
        ?int $monitorId,
        string $monitorName,
        string $target,
        string $monitorType,
        ?string $status,
        ?string $previousStatus,
        string $message,
        CarbonImmutable $at,
        ?int $durationSeconds,
        array $changes,
    ): AlertMessage {
        $base = rtrim(Config::string('app.url'), '/');
        $url = $monitorId === null ? $base : $base.'/monitors/'.$monitorId;
        $utc = $at->utc();

        $vars = [
            'kind' => $kind->value,
            'kind_label' => $kind->label(),
            'severity' => $kind->severity(),
            'status' => $status ?? '',
            'previous_status' => $previousStatus ?? '',
            'message' => $message,
            'at' => $utc->toIso8601String(),
            'at_local' => $utc->setTimezone($workspace->timezone)->format('Y-m-d H:i:s T'),
            'duration' => $durationSeconds === null ? '-' : Duration::human($durationSeconds),
            'duration_seconds' => $durationSeconds === null ? '' : (string) $durationSeconds,
            'changes' => $this->changeLines($changes),
            'monitor.id' => $monitorId === null ? '' : (string) $monitorId,
            'monitor.name' => $monitorName,
            'monitor.type' => $monitorType,
            'monitor.target' => $target,
            'monitor.url' => $url,
            'workspace.name' => $workspace->name,
        ];

        $templates = $channel->templates ?? [];
        $titleTemplate = $this->nonEmpty($templates['title'] ?? null) ?? DefaultTemplates::title();
        $bodyTemplate = $this->nonEmpty($templates['body'] ?? null) ?? DefaultTemplates::body($kind);

        return new AlertMessage(
            $kind,
            $kind->severity(),
            $workspace->id,
            $workspace->name,
            $monitorId,
            $monitorName,
            $monitorType,
            $target,
            $status,
            $previousStatus,
            $message,
            $utc,
            $durationSeconds,
            $changes,
            $url,
            TemplateRenderer::render($titleTemplate, $vars),
            TemplateRenderer::render($bodyTemplate, $vars),
        );
    }

    private function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @param  list<array{key: string, old: mixed, new: mixed}>  $changes
     */
    private function changeLines(array $changes): string
    {
        return implode("\n", array_map(
            fn (array $c): string => $c['key'].': '.$this->scalar($c['old'] ?? null).' → '.$this->scalar($c['new'] ?? null),
            $changes,
        ));
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => '-',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }

    /**
     * @param  list<AlertMessage>  $items
     */
    private function digestBody(array $items): string
    {
        $lines = [];
        foreach (array_slice($items, 0, self::DIGEST_LINES) as $item) {
            $lines[] = '['.$item->kind->label().'] '.$item->monitorName.': '.$item->message;
        }

        if (count($items) > self::DIGEST_LINES) {
            $lines[] = '… and '.(count($items) - self::DIGEST_LINES).' more';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<AlertMessage>  $items
     */
    private function mostSevere(array $items): string
    {
        $best = 'info';
        foreach ($items as $item) {
            if (self::SEVERITY_ORDER[$item->severity] > self::SEVERITY_ORDER[$best]) {
                $best = $item->severity;
            }
        }

        return $best;
    }
}
