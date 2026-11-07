<?php

namespace App\Alerting\Engine;

/**
 * The per-monitor and global settings that shape which alerts the alert machine sends.
 */
final readonly class AlertPolicy
{
    /**
     * @param  int  $resendIntervalMinutes  Reminder interval while a monitor stays down; 0 disables reminders.
     * @param  bool  $notifyDegraded  Whether degraded counts as a problem of its own rather than as ok.
     * @param  int  $degradedAlertAfterSeconds  How long degraded must persist before it is alerted.
     * @param  bool  $notifyChanges  Whether certificate, DNS and expiry-band changes are alerted.
     * @param  int  $flapThreshold  Class changes within the flap window that start a flapping episode.
     * @param  int  $flapWindowSeconds  Window in which class changes are counted.
     * @param  int  $flapStableSeconds  Time without a class change that ends a flapping episode.
     * @param  int  $suppressionRecheckSeconds  How often a suppressed monitor is re-evaluated.
     */
    public function __construct(
        public int $resendIntervalMinutes,
        public bool $notifyDegraded,
        public int $degradedAlertAfterSeconds,
        public bool $notifyChanges,
        public int $flapThreshold,
        public int $flapWindowSeconds,
        public int $flapStableSeconds,
        public int $suppressionRecheckSeconds,
    ) {}
}
