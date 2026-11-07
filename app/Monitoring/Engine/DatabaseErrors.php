<?php

namespace App\Monitoring\Engine;

use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * Classifies database exceptions by their SQLSTATE and driver error code.
 *
 * Driver messages can quote the values of a statement (a duplicate key, an incorrect string), so callers log
 * context() instead of the exception message.
 */
final class DatabaseErrors
{
    /** MySQL "Incorrect string value", reported with the generic SQLSTATE HY000. */
    private const MYSQL_INCORRECT_STRING = 1366;

    /** MySQL deadlock, reported with SQLSTATE 40001. */
    private const MYSQL_DEADLOCK = 1213;

    public static function isDatabaseError(Throwable $e): bool
    {
        return $e instanceof QueryException || $e instanceof PDOException;
    }

    /**
     * Whether the database refused the statement because of the data it carries (SQLSTATE class 22, data
     * exception, or class 23, integrity constraint violation): retrying the same data fails again.
     */
    public static function rejectsData(Throwable $e): bool
    {
        if (! self::isDatabaseError($e)) {
            return false;
        }

        $state = self::sqlState($e);

        return str_starts_with($state, '22') || str_starts_with($state, '23')
            || ($state === 'HY000' && self::driverCode($e) === self::MYSQL_INCORRECT_STRING);
    }

    /**
     * Whether the transaction was chosen as a deadlock or serialization victim and may succeed when retried.
     */
    public static function isDeadlock(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (! self::isDatabaseError($current)) {
                continue;
            }
            $state = self::sqlState($current);
            if ($state === '40001' || $state === '40P01' || self::driverCode($current) === self::MYSQL_DEADLOCK) {
                return true;
            }
        }

        return false;
    }

    /**
     * The SQLSTATE of a database exception, or an empty string when it carries none.
     */
    public static function sqlState(Throwable $e): string
    {
        $info = self::errorInfo($e);
        if (is_string($info[0] ?? null) && $info[0] !== '') {
            return $info[0];
        }

        $code = $e->getCode();
        if (is_string($code) && strlen($code) === 5) {
            return $code;
        }

        return preg_match('/SQLSTATE\[(\w{5})\]/', $e->getMessage(), $matches) === 1 ? $matches[1] : '';
    }

    /**
     * The driver-specific error code of a database exception, if it carries one.
     */
    public static function driverCode(Throwable $e): ?int
    {
        $info = self::errorInfo($e);
        if (is_int($info[1] ?? null)) {
            return $info[1];
        }
        if (is_string($info[1] ?? null) && ctype_digit($info[1])) {
            return (int) $info[1];
        }

        return preg_match('/SQLSTATE\[\w{5}\]: [^:]+: (\d+) /', $e->getMessage(), $matches) === 1 ? (int) $matches[1] : null;
    }

    /**
     * Log context for an exception: its class, plus the SQLSTATE and driver code of a database error. Never
     * the message, which may quote data.
     *
     * @return array{exception: class-string, sqlstate?: string, driver_code?: ?int}
     */
    public static function context(Throwable $e): array
    {
        $context = ['exception' => $e::class];
        if (self::isDatabaseError($e)) {
            $context['sqlstate'] = self::sqlState($e);
            $context['driver_code'] = self::driverCode($e);
        }

        return $context;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function errorInfo(Throwable $e): array
    {
        $info = $e instanceof QueryException || $e instanceof PDOException ? $e->errorInfo : null;

        return is_array($info) ? $info : [];
    }
}
