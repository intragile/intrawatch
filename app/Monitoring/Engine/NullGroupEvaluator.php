<?php

namespace App\Monitoring\Engine;

/**
 * A group evaluator that never changes anything.
 */
final class NullGroupEvaluator implements GroupEvaluator
{
    public function recompute(array $groupIds): array
    {
        return [];
    }
}
