<?php

namespace App\Alerting\Messages;

use App\Enums\AlertKind;
use Carbon\CarbonImmutable;

/**
 * One alert, resolved to everything a sender needs, including its rendered title and body.
 */
final readonly class AlertMessage
{
    /**
     * @param  list<array{key: string, old: mixed, new: mixed}>  $changes
     */
    public function __construct(
        public AlertKind $kind,
        public string $severity,
        public int $workspaceId,
        public string $workspaceName,
        public ?int $monitorId,
        public string $monitorName,
        public string $monitorType,
        public string $target,
        public ?string $status,
        public ?string $previousStatus,
        public string $message,
        public CarbonImmutable $at,
        public ?int $durationSeconds,
        public array $changes,
        public string $url,
        public string $title,
        public string $body,
    ) {}
}
