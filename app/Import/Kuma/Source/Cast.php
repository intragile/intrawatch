<?php

declare(strict_types=1);

namespace App\Import\Kuma\Source;

/**
 * Narrows loosely typed values read from a source into the scalar and map types the contract promises.
 */
final class Cast
{
    public static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public static function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    public static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Keeps the entries of a decoded JSON object, dropping non-string keys.
     *
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        $out = [];
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $out[(string) $key] = $item;
            }
        }

        return $out;
    }
}
