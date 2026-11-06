<?php

namespace App\Monitoring\Engine;

/**
 * Derives the status of group monitors from their children.
 */
interface GroupEvaluator
{
    /**
     * Recompute the given groups and their ancestors. Runs inside the caller's transaction and persists the
     * group states and their monitor events itself; the caller only broadcasts the returned transitions.
     *
     * @param  list<int>  $groupIds
     * @return list<Transition>
     */
    public function recompute(array $groupIds): array;
}
