<?php

namespace App\Alerting\Engine;

/**
 * The outcome of applying one signal: the next state and the alerts to record.
 */
final readonly class AlertDecision
{
    /**
     * @param  list<PlannedAlert>  $alerts
     */
    public function __construct(
        public AlertSnapshot $state,
        public array $alerts,
    ) {}
}
