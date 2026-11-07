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
}
