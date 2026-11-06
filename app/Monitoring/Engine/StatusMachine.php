<?php

namespace App\Monitoring\Engine;

use App\Enums\MonitorStatus;

/**
 * Turns a raw check outcome into the monitor's effective status. Pure: no I/O and no clock.
 */
final class StatusMachine
{
    public function apply(MonitorSpec $spec, StateSnapshot $state, CheckOutcome $o, bool $inMaintenance): Transition
    {
        if ($inMaintenance) {
            return $this->transition($spec, $state, MonitorStatus::Maintenance, $state->consecutiveFailures, $o->message);
        }

        $status = $o->status;
        $message = $o->message;

        if ($spec->inverted && $spec->type->definition()->supportsInverted()) {
            // A degraded result is a partial success, so it counts as the success an inverted monitor rejects.
            $status = $status === MonitorStatus::Down ? MonitorStatus::Up : MonitorStatus::Down;
            if ($status === MonitorStatus::Down && $message === '') {
                $message = 'Check succeeded, but the monitor expects a failure';
            }
        }

        if ($status === MonitorStatus::Up && $o->latencyMs !== null
            && $spec->degradedThresholdMs !== null && $o->latencyMs > $spec->degradedThresholdMs) {
            $status = MonitorStatus::Degraded;
            $message = "Slow response: {$o->latencyMs} ms";
        }

        if ($status !== MonitorStatus::Down) {
            return $this->transition($spec, $state, $status, 0, $message);
        }

        $failures = $state->consecutiveFailures + 1;
        $effective = $o->final || $failures > $spec->retries || $state->status === MonitorStatus::Down
            ? MonitorStatus::Down
            : MonitorStatus::Pending;

        return $this->transition($spec, $state, $effective, $failures, $message);
    }

    private function transition(MonitorSpec $spec, StateSnapshot $state, MonitorStatus $effective, int $failures, string $message): Transition
    {
        return new Transition(
            monitorId: $spec->id,
            workspaceId: $spec->workspaceId,
            from: $state->status,
            effective: $effective,
            consecutiveFailures: $failures,
            changed: $effective !== $state->status,
            nextDelaySeconds: $effective === MonitorStatus::Pending ? $spec->retryIntervalSeconds : $spec->intervalSeconds,
            message: $message,
            previousSince: $state->since,
        );
    }
}
