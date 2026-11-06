<?php

namespace App\Monitoring\Types;

/**
 * Syntax checks for the network addresses monitor configs carry. They never resolve anything.
 */
final class Hostname
{
    private const LABEL = '(?!-)[A-Za-z0-9-]{1,63}(?<!-)';

    public static function isIp(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * A DNS hostname (optionally with a trailing dot) or an IP literal.
     */
    public static function isHostOrIp(string $value): bool
    {
        return self::isIp($value) || self::isHostname($value);
    }

    public static function isHostname(string $value, int $minLabels = 1): bool
    {
        $value = rtrim($value, '.');
        if ($value === '' || strlen($value) > 253) {
            return false;
        }

        if (preg_match('/^'.self::LABEL.'(\.'.self::LABEL.')*$/', $value) !== 1) {
            return false;
        }

        return substr_count($value, '.') + 1 >= $minLabels;
    }

    /**
     * `ip` or `ip:port`; an IPv6 address with a port is written `[ip]:port`.
     */
    public static function isResolverAddress(string $value): bool
    {
        if (self::isIp($value)) {
            return true;
        }

        if (preg_match('/^\[([^\]]+)\]:(\d{1,5})$/', $value, $m) === 1) {
            return filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && self::isPort((int) $m[2]);
        }

        if (preg_match('/^([^:]+):(\d{1,5})$/', $value, $m) === 1) {
            return filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && self::isPort((int) $m[2]);
        }

        return false;
    }

    private static function isPort(int $port): bool
    {
        return $port >= 1 && $port <= 65535;
    }
}
