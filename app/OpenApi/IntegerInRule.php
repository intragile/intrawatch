<?php

namespace App\OpenApi;

use Dedoc\Scramble\Contracts\RuleTransformer;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\RuleTransforming\NormalizedRule;
use Dedoc\Scramble\Support\RuleTransforming\RuleTransformerContext;
use Illuminate\Validation\Rules\In;

/**
 * Scramble documents the values of an `in` rule as strings. On an integer field that yields an enum no
 * integer can match, so the values are documented as integers there. Runs after Scramble's own `in` rule.
 */
final class IntegerInRule implements RuleTransformer
{
    public function shouldHandle(NormalizedRule $rule): bool
    {
        return $rule->is('in') || $rule->instanceOf(In::class);
    }

    public function toSchema(Type $previous, NormalizedRule $rule, RuleTransformerContext $context): Type
    {
        if (! $previous instanceof IntegerType || $previous->enum === []) {
            return $previous;
        }

        $values = [];
        foreach ($previous->enum as $value) {
            if (! is_numeric($value) || (string) (int) $value !== (string) $value) {
                return $previous;
            }
            $values[] = (int) $value;
        }

        return $previous->enum($values);
    }
}
