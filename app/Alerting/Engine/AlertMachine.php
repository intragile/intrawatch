<?php

namespace App\Alerting\Engine;

use App\Alerting\Engine\Signals\ChangeSignal;
use App\Alerting\Engine\Signals\StatusSignal;
use App\Alerting\Engine\Signals\TickSignal;
use App\Enums\AlertKind;
use App\Enums\MonitorStatus;
use Carbon\CarbonImmutable;

/**
 * Decides which alerts a monitor's status changes, watched-value changes and the passing of time produce.
 *
 * The machine is pure: the same policy, state, signal and suppression flag always give the same decision. It
 * holds the working copy of one snapshot while a signal is applied and is never shared between calls.
 */
final class AlertMachine
{
    private ?AlertClass $notified;

    private ?AlertClass $observed;

    private ?MonitorStatus $current;

    private ?CarbonImmutable $problemSince;

    private ?CarbonImmutable $downSince;

    private ?CarbonImmutable $degradedSince;

    private bool $flapping;

    private ?CarbonImmutable $flappingSince;

    /** @var list<int> */
    private array $recentChanges;

    private ?CarbonImmutable $lastNotifiedAt;

    private int $remindersSent;

    private bool $stateSuppressed;

    private ?CarbonImmutable $lastSignalAt;

    /** @var list<PlannedAlert> */
    private array $alerts = [];

    private function __construct(
        private readonly AlertPolicy $policy,
        AlertSnapshot $state,
        private readonly bool $suppressed,
    ) {
        $this->notified = $state->notified;
        $this->observed = $state->observed;
        $this->current = $state->current;
        $this->problemSince = $state->problemSince;
        $this->downSince = $state->downSince;
        $this->degradedSince = $state->degradedSince;
        $this->flapping = $state->flapping;
        $this->flappingSince = $state->flappingSince;
        $this->recentChanges = $state->recentChanges;
        $this->lastNotifiedAt = $state->lastNotifiedAt;
        $this->remindersSent = $state->remindersSent;
        $this->stateSuppressed = $state->suppressed;
        $this->lastSignalAt = $state->lastSignalAt;
    }

    /**
     * Apply one signal to a monitor's alerting state.
     *
     * @param  bool  $suppressed  Whether an ancestor group is down and has an active channel right now.
     */
    public static function apply(AlertPolicy $policy, AlertSnapshot $state, StatusSignal|ChangeSignal|TickSignal $signal, bool $suppressed): AlertDecision
    {
        $at = $signal instanceof TickSignal ? $signal->now : $signal->at;

        // A signal older than one already applied arrived out of order and changes nothing.
        if (! $signal instanceof TickSignal && $state->lastSignalAt !== null && $at->lessThan($state->lastSignalAt)) {
            return new AlertDecision($state, []);
        }

        $machine = new self($policy, $state, $suppressed);
        match (true) {
            $signal instanceof StatusSignal => $machine->onStatus($signal),
            $signal instanceof ChangeSignal => $machine->onChange($signal),
            $signal instanceof TickSignal => $machine->onTick($signal->now),
        };

        return new AlertDecision($machine->snapshot($at), $machine->alerts);
    }

    private function onStatus(StatusSignal $signal): void
    {
        $t = $signal->at;
        $this->lastSignalAt = $t;
        $this->current = $signal->to;

        if (self::isMuted($signal->to)) {
            $this->degradedSince = null;

            return;
        }

        $cls = AlertClass::of($signal->to, $this->policy->notifyDegraded);
        if ($cls === null) {
            return;
        }

        $previous = $this->observed;
        $changed = $cls !== $previous;
        if ($changed) {
            if ($previous !== null) {
                $this->recordChange($t);
            }
            $this->observed = $cls;
        }

        // The start times follow the observed class, also when it is re-entered after maintenance.
        if ($cls === AlertClass::Down && $this->downSince === null) {
            // The outage starts at the first failed check, not at its confirmation.
            $this->downSince = $signal->from === MonitorStatus::Pending ? $signal->previousSince : $t;
        }
        if ($cls !== AlertClass::Down) {
            $this->downSince = null;
        }
        if ($cls === AlertClass::Degraded && $this->degradedSince === null) {
            $this->degradedSince = $t;
        }
        if ($cls === AlertClass::Ok) {
            $this->degradedSince = null;
        }

        if ($this->flapping) {
            return;
        }
        if (count($this->recentChanges) >= $this->policy->flapThreshold) {
            $this->flapping = true;
            $this->flappingSince = $t;
            $this->alerts[] = $this->alert(
                AlertKind::Flapping,
                $signal->to,
                self::statusOf($this->notified),
                count($this->recentChanges).' status changes in '.self::minutes($this->policy->flapWindowSeconds).' min',
                null,
            );

            return;
        }

        // A withheld alert is recorded once; a replay of the same class adds no second record.
        $this->transition($cls, $t, $signal->message, $changed);
        if ($cls === AlertClass::Degraded && $this->notifiedIsOk() && $this->policy->degradedAlertAfterSeconds === 0) {
            $this->degradedHold($t, $signal->message, $changed);
        }
    }

    private function onChange(ChangeSignal $signal): void
    {
        $this->lastSignalAt = $signal->at;

        if (! $this->policy->notifyChanges || ($this->current !== null && self::isMuted($this->current))) {
            return;
        }

        $issuerChange = null;
        $fingerprintChange = null;
        $improved = null;
        foreach ($signal->changes as $change) {
            if ($change['key'] === 'tls.issuer') {
                $issuerChange = $change;
            } elseif ($change['key'] === 'tls.fingerprint') {
                $fingerprintChange = $change;
            } elseif ($improved === null && self::bandDirection($change) > 0) {
                $improved = $change;
            }
        }

        foreach ($signal->changes as $change) {
            $key = $change['key'];
            if (str_ends_with($key, '.expiry_band')) {
                $direction = self::bandDirection($change);
                if ($direction < 0) {
                    $this->alerts[] = $this->alert(AlertKind::Expiring, null, null, ExpiryBand::message($key, $change['new']), null, [$change]);
                } elseif ($change === $improved) {
                    // A renewal also changes the fingerprint: one alert carries every change of the check.
                    $this->alerts[] = $this->alert(AlertKind::Renewed, null, null, ExpiryBand::renewedMessage($key), null, $signal->changes);
                }
            } elseif ($key === 'tls.issuer') {
                $changes = $fingerprintChange === null ? [$change] : [$change, $fingerprintChange];
                $this->alerts[] = $this->alert(AlertKind::Changed, null, null, 'Certificate issuer changed', null, $changes);
            } elseif ($key === 'tls.fingerprint') {
                if ($issuerChange === null && $improved === null) {
                    $this->alerts[] = $this->alert(AlertKind::Changed, null, null, 'Certificate changed', null, [$change]);
                }
            } elseif ($key === 'dns.records') {
                $this->alerts[] = $this->alert(AlertKind::Changed, null, null, 'DNS records changed', null, [$change]);
            } else {
                $this->alerts[] = $this->alert(AlertKind::Changed, null, null, "{$key} changed", null, [$change]);
            }
        }
    }

    private function onTick(CarbonImmutable $now): void
    {
        $lastChange = $this->recentChanges === [] ? null : max($this->recentChanges);
        if ($this->flapping && ($lastChange === null || $now->getTimestamp() >= $lastChange + $this->policy->flapStableSeconds)) {
            $this->alerts[] = $this->alert(
                AlertKind::Stable,
                $this->current,
                self::statusOf($this->notified),
                'No status change for '.self::minutes($this->policy->flapStableSeconds).' min',
                null,
            );
            $this->flapping = false;
            $this->recentChanges = [];
            $this->notified = $this->observed;
            if ($this->observed === AlertClass::Down) {
                $this->problemSince = $this->downSince;
                $this->lastNotifiedAt = $now;
                $this->remindersSent = 0;
            } elseif ($this->observed === AlertClass::Degraded) {
                $this->problemSince ??= $this->degradedSince;
            } else {
                $this->problemSince = null;
            }
        }

        if ($this->degradedHoldEnd() !== null && $now->greaterThanOrEqualTo($this->degradedHoldEnd())) {
            // While suppressed, the withheld alert is recorded only the first time.
            $message = 'Degraded for '.self::minutes($this->secondsSince($this->degradedSince, $now) ?? 0).' min';
            $this->degradedHold($now, $message, ! $this->stateSuppressed);
        }

        $reminderAt = $this->nextReminder();
        if ($reminderAt !== null && $now->greaterThanOrEqualTo($reminderAt)) {
            $duration = $this->secondsSince($this->downSince, $now);
            $this->alerts[] = $this->alert(
                AlertKind::StillDown,
                MonitorStatus::Down,
                MonitorStatus::Down,
                'Still down after '.self::minutes($duration ?? 0).' min',
                $duration,
            );
            $this->lastNotifiedAt = $now;
            $this->remindersSent++;
        }

        if ($this->stateSuppressed && ! $this->suppressed) {
            $this->stateSuppressed = false;
            // A flapping episode reports itself; its end aligns the notified class.
            if (! $this->flapping && $this->observed !== null && $this->observed !== $this->notified) {
                $this->transition($this->observed, $now, self::caughtUpMessage($this->observed), true);
            }
        }
    }

    /**
     * Report a move to $cls that users have not been told about yet: Down, Up, or Degraded when it closes a
     * down alert. Degraded after ok is subject to the hold and handled by degradedHold().
     */
    private function transition(AlertClass $cls, CarbonImmutable $at, string $message, bool $recordWithheld): void
    {
        $notified = $this->notified;

        if ($cls === AlertClass::Down && $notified !== AlertClass::Down) {
            $this->emit(AlertKind::Down, MonitorStatus::Down, $message, null, $recordWithheld, function () use ($at): void {
                $this->notified = AlertClass::Down;
                $this->problemSince = $this->downSince;
                $this->lastNotifiedAt = $at;
                $this->remindersSent = 0;
            });
        } elseif ($cls === AlertClass::Ok && ($notified === AlertClass::Down || $notified === AlertClass::Degraded)) {
            $this->emit(AlertKind::Up, MonitorStatus::Up, $message, $this->secondsSince($this->problemSince, $at), $recordWithheld, function () use ($at): void {
                $this->notified = AlertClass::Ok;
                $this->problemSince = null;
                $this->downSince = null;
                $this->lastNotifiedAt = $at;
            });
        } elseif ($cls === AlertClass::Ok) {
            // A new monitor's first up is not news.
            $this->notified = AlertClass::Ok;
        } elseif ($cls === AlertClass::Degraded && $notified === AlertClass::Down) {
            $this->emit(AlertKind::Degraded, MonitorStatus::Degraded, $message, $this->secondsSince($this->problemSince, $at), $recordWithheld, function () use ($at): void {
                $this->notified = AlertClass::Degraded;
                $this->downSince = null;
                $this->lastNotifiedAt = $at;
            });
        }
    }

    /**
     * Report degraded once it has persisted for the configured hold.
     */
    private function degradedHold(CarbonImmutable $at, string $message, bool $recordWithheld): void
    {
        $this->emit(AlertKind::Degraded, MonitorStatus::Degraded, $message, $this->secondsSince($this->degradedSince, $at), $recordWithheld, function () use ($at): void {
            $this->notified = AlertClass::Degraded;
            $this->problemSince = $this->degradedSince;
            $this->lastNotifiedAt = $at;
        });
    }

    /**
     * Emit a status alert. While suppressed it is recorded as suppressed (or not at all for a replay) and the
     * notified state stays as it was, so the alert is sent once the suppression ends.
     *
     * @param  callable(): void  $notify  Moves the notified state forward.
     */
    private function emit(AlertKind $kind, MonitorStatus $status, string $message, ?int $duration, bool $recordWithheld, callable $notify): void
    {
        $previous = self::statusOf($this->notified);

        if ($this->suppressed) {
            $this->stateSuppressed = true;
            if ($recordWithheld) {
                $this->alerts[] = $this->alert($kind, $status, $previous, $message, $duration, [], true);
            }

            return;
        }

        $this->alerts[] = $this->alert($kind, $status, $previous, $message, $duration);
        $notify();
    }

    private function recordChange(CarbonImmutable $t): void
    {
        $since = $t->getTimestamp() - $this->policy->flapWindowSeconds;
        $kept = array_filter($this->recentChanges, static fn (int $at): bool => $at >= $since);
        $kept[] = $t->getTimestamp();
        $this->recentChanges = array_values($kept);
    }

    private function degradedHoldEnd(): ?CarbonImmutable
    {
        if ($this->flapping || $this->observed !== AlertClass::Degraded || ! $this->notifiedIsOk()
            || $this->degradedSince === null || ($this->current !== null && self::isMuted($this->current))) {
            return null;
        }

        return $this->degradedSince->addSeconds($this->policy->degradedAlertAfterSeconds);
    }

    private function nextReminder(): ?CarbonImmutable
    {
        if ($this->flapping || $this->notified !== AlertClass::Down || $this->observed !== AlertClass::Down
            || $this->policy->resendIntervalMinutes <= 0 || $this->lastNotifiedAt === null
            || ($this->current !== null && self::isMuted($this->current))) {
            return null;
        }

        return $this->lastNotifiedAt->addMinutes($this->policy->resendIntervalMinutes);
    }

    private function snapshot(CarbonImmutable $at): AlertSnapshot
    {
        $candidates = [
            // While suppressed the recheck covers a hold that has ended but cannot be sent yet.
            $this->stateSuppressed ? null : $this->degradedHoldEnd(),
            $this->nextReminder(),
            $this->flapping && $this->recentChanges !== []
                ? CarbonImmutable::createFromTimestampUTC(max($this->recentChanges) + $this->policy->flapStableSeconds)
                : null,
            $this->stateSuppressed ? $at->addSeconds($this->policy->suppressionRecheckSeconds) : null,
        ];

        $dueAt = null;
        foreach ($candidates as $candidate) {
            if ($candidate !== null && ($dueAt === null || $candidate->lessThan($dueAt))) {
                $dueAt = $candidate;
            }
        }

        return new AlertSnapshot(
            $this->notified,
            $this->observed,
            $this->current,
            $this->problemSince,
            $this->downSince,
            $this->degradedSince,
            $this->flapping,
            $this->flappingSince,
            $this->recentChanges,
            $this->lastNotifiedAt,
            $this->remindersSent,
            $this->stateSuppressed,
            $this->lastSignalAt,
            $dueAt,
        );
    }

    /**
     * @param  list<array{key: string, old: ?string, new: string}>  $changes
     */
    private function alert(
        AlertKind $kind,
        ?MonitorStatus $status,
        ?MonitorStatus $previous,
        string $message,
        ?int $duration,
        array $changes = [],
        bool $suppressed = false,
    ): PlannedAlert {
        return new PlannedAlert($kind, $status, $previous, $message, $duration, $changes, $suppressed);
    }

    private function notifiedIsOk(): bool
    {
        return $this->notified === null || $this->notified === AlertClass::Ok;
    }

    private function secondsSince(?CarbonImmutable $since, CarbonImmutable $at): ?int
    {
        return $since === null ? null : max(0, $at->getTimestamp() - $since->getTimestamp());
    }

    /**
     * Positive when an expiry band moved to a less urgent band, negative when to a more urgent one, 0 when the
     * change is not a band change or has no previous value to compare with.
     *
     * @param  array{key: string, old: ?string, new: string}  $change
     */
    private static function bandDirection(array $change): int
    {
        if (! str_ends_with($change['key'], '.expiry_band') || $change['old'] === null) {
            return 0;
        }

        return ExpiryBand::rank($change['new']) <=> ExpiryBand::rank($change['old']);
    }

    private static function isMuted(MonitorStatus $status): bool
    {
        return $status === MonitorStatus::Maintenance || $status === MonitorStatus::Paused;
    }

    /**
     * The monitor status users were last told about for a notified class.
     */
    private static function statusOf(?AlertClass $class): ?MonitorStatus
    {
        return match ($class) {
            AlertClass::Ok => MonitorStatus::Up,
            AlertClass::Degraded => MonitorStatus::Degraded,
            AlertClass::Down => MonitorStatus::Down,
            null => null,
        };
    }

    private static function caughtUpMessage(AlertClass $class): string
    {
        return match ($class) {
            AlertClass::Ok => 'Up',
            AlertClass::Degraded => 'Degraded',
            AlertClass::Down => 'Down',
        };
    }

    private static function minutes(int $seconds): string
    {
        return $seconds % 60 === 0 ? (string) intdiv($seconds, 60) : (string) round($seconds / 60, 1);
    }
}
