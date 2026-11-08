<?php

namespace App\Support;

/**
 * Lists the names of stored secrets; the values are never exposed.
 */
final class SecretKeys
{
    /**
     * @param  array<array-key, mixed>|null  $secrets
     * @return array<int, string>
     */
    public static function of(?array $secrets): array
    {
        return collect(array_keys($secrets ?? []))
            ->map(static fn (int|string $key): string => (string) $key)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Whether any of the destination keys (dotted paths) holds a different value after the change. Scalars are
     * compared as strings, so `587` and `"587"` match; objects are compared with their keys sorted.
     *
     * @param  array<array-key, mixed>  $before
     * @param  array<array-key, mixed>  $after
     * @param  list<string>  $keys
     */
    public static function destinationChanged(array $before, array $after, array $keys): bool
    {
        foreach ($keys as $key) {
            if (self::canonical(data_get($before, $key)) !== self::canonical(data_get($after, $key))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The stored secret names that the request did not send again. A key sent as null counts as sent: it removes
     * the secret on purpose.
     *
     * @param  array<array-key, mixed>|null  $stored
     * @param  list<string>  $submittedKeys
     * @return list<string>
     */
    public static function unresent(?array $stored, array $submittedKeys): array
    {
        return array_values(array_diff(self::of($stored), $submittedKeys));
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            $value = array_map(self::canonical(...), $value);
            if (! array_is_list($value)) {
                ksort($value);
            }

            return $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? (string) $value : $value;
    }
}
