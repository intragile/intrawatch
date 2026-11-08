<?php

namespace App\Support;

/**
 * Lists the names of stored secrets; the values are never exposed.
 */
final class SecretKeys
{
    /**
     * The per-secret error when a destination change leaves a stored secret unconfirmed.
     */
    public const REENTER_SECRET = 'Re-enter this secret when changing the destination, or remove it with null.';

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

    /**
     * The stored secrets that must be sent again because the destination changed. Secrets are bound to the
     * destination they were entered for, so pointing the config somewhere else must re-confirm each one (or
     * remove it with null); otherwise a stored secret would silently follow the config to a new host.
     * Returns an empty list when nothing is stored, the new config is not an array, or the destination stays.
     *
     * @param  array<array-key, mixed>|null  $storedSecrets
     * @param  array<array-key, mixed>  $before
     * @param  list<string>  $destinationKeys
     * @param  list<string>  $submittedKeys
     * @return list<string>
     */
    public static function missingForDestinationChange(?array $storedSecrets, array $before, mixed $after, array $destinationKeys, array $submittedKeys): array
    {
        if (($storedSecrets ?? []) === [] || ! is_array($after) || ! self::destinationChanged($before, $after, $destinationKeys)) {
            return [];
        }

        return self::unresent($storedSecrets, $submittedKeys);
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
