<?php

namespace App\Alerting\Engine;

use App\Enums\MonitorStatus;
use Carbon\CarbonImmutable;

/**
 * The persisted alerting state of one monitor, mirroring a row of `alert_states`.
 */
final readonly class AlertSnapshot
{
    /**
     * @param  ?AlertClass  $notified  The class users were last told about.
     * @param  ?AlertClass  $observed  The class of the last non-pending, non-muted status.
     * @param  ?MonitorStatus  $current  The last status received, muted and pending included.
     * @param  list<int>  $recentChanges  Unix timestamps of the class changes inside the flap window.
     */
    public function __construct(
        public ?AlertClass $notified,
        public ?AlertClass $observed,
        public ?MonitorStatus $current,
        public ?CarbonImmutable $problemSince,
        public ?CarbonImmutable $downSince,
        public ?CarbonImmutable $degradedSince,
        public bool $flapping,
        public ?CarbonImmutable $flappingSince,
        public array $recentChanges,
        public ?CarbonImmutable $lastNotifiedAt,
        public int $remindersSent,
        public bool $suppressed,
        public ?CarbonImmutable $lastSignalAt,
        public ?CarbonImmutable $dueAt,
    ) {}

    /**
     * The state of a monitor nothing has been observed for yet.
     */
    public static function initial(): self
    {
        return new self(null, null, null, null, null, null, false, null, [], null, 0, false, null, null);
    }
}
