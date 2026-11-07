<?php

namespace App\OpenApi;

use App\Support\JsonObject;
use Dedoc\Scramble\Infer\Extensions\Event\StaticMethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\StaticMethodReturnTypeExtension;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\KeyedArrayType;
use Dedoc\Scramble\Support\Type\MixedType;
use Dedoc\Scramble\Support\Type\NullType;
use Dedoc\Scramble\Support\Type\StringType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\Union;

/**
 * `JsonObject::of()` serialises to an object with the same keys as the map it receives, so it is documented
 * as that map (or null) rather than as an opaque object.
 */
final class JsonObjectReturnType implements StaticMethodReturnTypeExtension
{
    public function shouldHandle(string $name): bool
    {
        return $name === JsonObject::class;
    }

    public function getStaticMethodReturnType(StaticMethodCallEvent $event): ?Type
    {
        if ($event->name !== 'of') {
            return null;
        }

        $map = $event->getArg('map', 0, new NullType);
        $types = $map instanceof Union ? $map->types : [$map];

        $documented = [];
        foreach ($types as $type) {
            $documented[] = $type instanceof ArrayType || $type instanceof KeyedArrayType || $type instanceof NullType
                ? $type
                : new ArrayType(new MixedType, new StringType);
        }

        return Union::wrap($documented);
    }
}
