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
            $this->stateSuppressed = $this->stateSuppressed || $this->suppressed;
            $this->alerts[] = $this->alert(
                AlertKind::Flapping,
                $signal->to,
                self::statusOf($this->notified),
                count($this->recentChanges).' status changes in '.self::minutes($this->policy->flapWindowSeconds).' min',
                null,
                [],
                $this->suppressed,
            );

            return;
        }

        // A withheld alert is recorded once; a replay of the same class adds no second record.
        $this->transition($cls, $t, $signal->message, $changed);
        if ($cls === AlertClass::Degraded && $this->notifiedIsOk() && $this->policy->degradedAlertAfterSeconds === 0) {
            $this->degradedHold($t, $signal->message);
        }
    }

    private function onChange(ChangeSignal $signal): void
    {
        $this->lastSignalAt = $signal->at;

        if (! $this->policy->notifyChanges || $this->isCurrentlyMuted()) {
            return;
        }

        $issuerChange = null;
        $fingerprintChange = null;
        $tlsRenewed = false;
        foreach ($signal->changes as $change) {
            if ($change['key'] === 'tls.issuer') {
                $issuerChange = $change;
            } elseif ($change['key'] === 'tls.fingerprint') {
                $fingerprintChange = $change;
            } elseif ($change['key'] === 'tls.expiry_band' && self::bandDirection($change) > 0) {
                $tlsRenewed = true;
            }
        }

        foreach ($signal->changes as $change) {
            $key = $change['key'];
            if (str_ends_with($key, '.expiry_band')) {
                $direction = self::bandDirection($change);
                if ($direction < 0) {
                    $this->alerts[] = $this->alert(AlertKind::Expiring, null, null, ExpiryBand::message($key, $change['new']), null, [$change]);
                } elseif ($direction > 0) {
                    // A certificate renewal also changes the fingerprint: one alert carries both.
                    $changes = $key === 'tls.expiry_band' && $fingerprintChange !== null ? [$change, $fingerprintChange] : [$change];
                    $this->alerts[] = $this->alert(AlertKind::Renewed, null, null, ExpiryBand::renewedMessage($key), null, $changes);
                }
            } elseif ($key === 'tls.issuer') {
                $changes = $fingerprintChange === null ? [$change] : [$change, $fingerprintChange];
                $this->alerts[] = $this->alert(AlertKind::Changed, null, null, 'Certificate issuer changed', null, $changes);
            } elseif ($key === 'tls.fingerprint') {
                if ($issuerChange === null && ! $tlsRenewed) {
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
        $flapEnd = $this->flapEnd();
        if ($flapEnd !== null && $now->greaterThanOrEqualTo($flapEnd)) {
            $this->endFlapping($now);
        }

        if ($this->degradedHoldEnd() !== null && $now->greaterThanOrEqualTo($this->degradedHoldEnd())) {
            $this->degradedHold($now, 'Degraded for '.self::minutes($this->secondsSince($this->degradedSince, $now) ?? 0).' min');
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
            // A flapping episode reports itself and its end aligns the notified class. Nothing is caught up while
            // muted: the status that ends maintenance or the pause is applied through the normal path.
            if (! $this->flapping && ! $this->isCurrentlyMuted() && $this->observed !== null && $this->observed !== $this->notified) {
                $this->transition($this->observed, $now, self::caughtUpMessage($this->observed), true);
            }
        }
    }

    /**
     * The moment the current flapping episode ends, or null when not flapping or while muted (the episode is
     * then ended by the first tick after the status that ends the maintenance or pause).
     *
     * An episode ends after flapStable seconds without a class change, or once it has lasted a full window and
     * fewer than two class changes fall within the last window.
     */
    private function flapEnd(): ?CarbonImmutable
    {
        if (! $this->flapping || $this->isCurrentlyMuted()) {
            return null;
        }
        if ($this->recentChanges === [] || $this->flappingSince === null) {
            return $this->flappingSince ?? CarbonImmutable::createFromTimestampUTC(0);
        }

        $window = $this->policy->flapWindowSeconds;
        $changes = $this->recentChanges;
        sort($changes);
        $last = $changes[count($changes) - 1];
        $quiet = $last + $this->policy->flapStableSeconds;

        // The window [now - window, now] holds fewer than two changes once the second latest one has left it.
        $secondLast = count($changes) >= 2 ? $changes[count($changes) - 2] : null;
        $rate = max($this->flappingSince->getTimestamp() + $window, $secondLast === null ? 0 : $secondLast + $window + 1);

        return CarbonImmutable::createFromTimestampUTC(min($quiet, $rate));
    }

    private function endFlapping(CarbonImmutable $now): void
    {
        $changes = $this->recentChanges;
        $quiet = $changes === [] || $now->getTimestamp() >= max($changes) + $this->policy->flapStableSeconds;
        $message = $quiet
            ? 'No status change for '.self::minutes($this->policy->flapStableSeconds).' min'
            : 'Fewer than 2 status changes in '.self::minutes($this->policy->flapWindowSeconds).' min';

        $this->alerts[] = $this->alert(AlertKind::Stable, $this->current, self::statusOf($this->notified), $message, null, [], $this->suppressed);
        $this->flapping = false;
        $this->recentChanges = [];

        if ($this->suppressed) {
            // Only a recovery from a delivered down is sent now; anything else is caught up after the suppression.
            $this->stateSuppressed = true;
            if ($this->observed !== null && $this->observed !== $this->notified) {
                $this->transition($this->observed, $now, self::caughtUpMessage($this->observed), false);
            }

            return;
        }

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
            // A new monitor's first up is not news. Any withheld degraded problem is over.
            $this->notified = AlertClass::Ok;
            $this->problemSince = null;
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
     *
     * While the notified class is ok, a non-null problemSince marks a withheld degraded alert that has already
     * been recorded, so it is recorded once however many ticks or replays follow.
     */
    private function degradedHold(CarbonImmutable $at, string $message): void
    {
        $this->emit(AlertKind::Degraded, MonitorStatus::Degraded, $message, $this->secondsSince($this->degradedSince, $at), $this->problemSince === null, function () use ($at): void {
            $this->notified = AlertClass::Degraded;
            $this->problemSince = $this->degradedSince;
            $this->lastNotifiedAt = $at;
        });

        if ($this->suppressed) {
            $this->problemSince = $this->degradedSince;
        }
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

        // Recovery from a down users were told about is never held back.
        $closesDeliveredDown = ($kind === AlertKind::Up || $kind === AlertKind::Degraded) && $this->notified === AlertClass::Down;

        if ($this->suppressed && ! $closesDeliveredDown) {
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
        if (! $this->policy->notifyDegraded || $this->flapping || $this->observed !== AlertClass::Degraded
            || ! $this->notifiedIsOk() || $this->degradedSince === null || $this->isCurrentlyMuted()) {
            return null;
        }

        return $this->degradedSince->addSeconds($this->policy->degradedAlertAfterSeconds);
    }

    private function nextReminder(): ?CarbonImmutable
    {
        if ($this->flapping || $this->notified !== AlertClass::Down || $this->observed !== AlertClass::Down
            || $this->policy->resendIntervalMinutes <= 0 || $this->lastNotifiedAt === null || $this->isCurrentlyMuted()) {
            return null;
        }

        return $this->lastNotifiedAt->addMinutes($this->policy->resendIntervalMinutes);
    }

    private function snapshot(CarbonImmutable $at): AlertSnapshot
    {
        $candidates = [
            // Once a withheld degraded alert is recorded, the suppression recheck takes over.
            $this->stateSuppressed && $this->problemSince !== null ? null : $this->degradedHoldEnd(),
            $this->nextReminder(),
            $this->flapEnd(),
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

    private function isCurrentlyMuted(): bool
    {
        return $this->current !== null && self::isMuted($this->current);
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
