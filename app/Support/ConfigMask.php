<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Masks the sensitive values of a config array: an http(s) URL keeps its scheme, host and port, anything else
 * becomes the mask. One class serves both the API responses and the append-only audit log, so a value hidden
 * from a caller is never readable in the log either.
 */
final class ConfigMask
{
    public const MASK = '***';

    /**
     * Keys may be dotted paths; a missing key is skipped and a null value stays null.
     *
     * @param  array<array-key, mixed>|null  $config
     * @param  list<string>  $keys
     * @return ($config is null ? null : array<array-key, mixed>)
     */
    public static function apply(?array $config, array $keys): ?array
    {
        if ($config === null) {
            return null;
        }
        foreach ($keys as $key) {
            $config = self::maskPath($config, explode('.', $key));
        }

        return $config;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $path
     * @return array<array-key, mixed>
     */
    private static function maskPath(array $values, array $path): array
    {
        $head = array_shift($path);
        if ($head === null || ! isset($values[$head])) {
            return $values;
        }
        if ($path === []) {
            $values[$head] = self::mask($values[$head]);
        } elseif (is_array($values[$head])) {
            $values[$head] = self::maskPath($values[$head], $path);
        }

        return $values;
    }

    private static function mask(mixed $value): string
    {
        if (is_string($value)) {
            $parts = parse_url($value);
            if (is_array($parts) && isset($parts['scheme'], $parts['host']) && in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
                $port = isset($parts['port']) ? ':'.$parts['port'] : '';

                return strtolower($parts['scheme']).'://'.$parts['host'].$port.'/'.self::MASK;
            }
        }

        return self::MASK;
    }
}
