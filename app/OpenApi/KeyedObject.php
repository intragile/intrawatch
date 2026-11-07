<?php

namespace App\OpenApi;

use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\Types\MixedType;
use Dedoc\Scramble\Support\Generator\Types\NullType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;

/**
 * Documents a field as a JSON object with free-form keys, such as a type-specific `config` map. It only
 * appears in documented rule sets and never takes part in validation.
 */
final class KeyedObject
{
    private function __construct(private readonly bool $secrets) {}

    /**
     * An object whose values may be anything.
     */
    public static function ofAny(): self
    {
        return new self(false);
    }

    /**
     * A secrets object: each value is a string or a map of strings (such as secret headers), or null to
     * remove the stored secret.
     */
    public static function ofSecrets(): self
    {
        return new self(true);
    }

    /**
     * Called by Scramble's rule-to-schema transformer.
     */
    public function docs(Type $type): Type
    {
        $object = new ObjectType;
        if (! $this->secrets) {
            $object->additionalProperties(new MixedType);

            return $object;
        }

        $map = new ObjectType;
        $map->additionalProperties(new StringType);
        $value = new AnyOf;
        $value->setItems([new StringType, $map, new NullType]);
        $object->additionalProperties($value);

        return $object;
    }
}
