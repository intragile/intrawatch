<?php

namespace App\Monitoring\Engine;

/**
 * What one ResultRecorder call persisted.
 */
final readonly class RecordedBatch
{
    /**
     * @param  array<int, Transition>  $transitions  The last transition of each recorded monitor, keyed by id.
     * @param  list<Transition>  $groupTransitions  Transitions of group monitors recomputed in the same transaction.
     */
    public function __construct(
        public array $transitions = [],
        public array $groupTransitions = [],
    ) {}
}
