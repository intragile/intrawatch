<?php

namespace App\Support;

/**
 * Forces a map to serialise as a JSON object, so an empty one stays `{}` instead of becoming `[]`.
 */
final class JsonObject
{
    /**
     * @param  array<array-key, mixed>|null  $map
     */
    public static function of(?array $map): ?object
    {
        return $map === null ? null : (object) $map;
    }
}
