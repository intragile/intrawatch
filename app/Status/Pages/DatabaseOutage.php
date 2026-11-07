<?php

namespace App\Status\Pages;

use App\Monitoring\Engine\DatabaseErrors;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Database\LostConnectionDetector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use PDOException;
use Throwable;

/**
 * A short "database down" marker shared by every web process through the status page file cache.
 *
 * When a public request cannot reach the database, the marker is set for 30 s. Only connection failures set
 * it: an error in one query (a missing table, a locked SQLite file) says nothing about other pages, which keep
 * rendering, while the failing entry backs off on its own. Until it expires, public
 * requests that would need the database fail at once, answering from a cached copy or with a 503, instead of
 * each making its own connection attempt: against an unreachable host every attempt would hold a worker for
 * the whole connect timeout.
 */
final class DatabaseOutage
{
    public const SECONDS = 30;

    private const KEY = 'status:db-down';

    /** MySQL client errors for a server that cannot be reached or was lost: 2002, 2003, 2005, 2006, 2013. */
    private const MYSQL_CONNECTION_ERRORS = [2002, 2003, 2005, 2006, 2013];

    /**
     * Records a failure seen on the public path.
     */
    public function record(): void
    {
        try {
            $until = CarbonImmutable::now()->getTimestamp() + self::SECONDS;
            $this->store()->put(self::KEY, $until, self::SECONDS);
        } catch (Throwable $e) {
            // Without the marker each request retries the database, as it would without this class.
            report($e);
        }
    }

    /**
     * Records the failure only when it shows the database cannot be reached.
     */
    public function recordIfUnreachable(Throwable $e): void
    {
        if (self::isConnectionFailure($e)) {
            $this->record();
        }
    }

    /**
     * Whether the exception, or one it wraps, is a failure to connect to the database or a lost connection:
     * Laravel's lost-connection messages, SQLSTATE class 08 (connection exception, which covers a PostgreSQL
     * connect timeout), or a MySQL client connection error code.
     */
    public static function isConnectionFailure(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (! $current instanceof QueryException && ! $current instanceof PDOException) {
                continue;
            }
            if (app(LostConnectionDetector::class)->causedByLostConnection($current)) {
                return true;
            }
            if (str_starts_with(DatabaseErrors::sqlState($current), '08')) {
                return true;
            }
            if (in_array(self::mysqlCode($current), self::MYSQL_CONNECTION_ERRORS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The MySQL error code: from the error info of a failed statement, or the exception code and the
     * `[2002]`-style message of a failed connection attempt, which carries no error info.
     */
    private static function mysqlCode(QueryException|PDOException $e): ?int
    {
        $code = DatabaseErrors::driverCode($e);
        if ($code !== null) {
            return $code;
        }
        if (is_int($e->getCode()) && $e->getCode() !== 0) {
            return $e->getCode();
        }

        return preg_match('/SQLSTATE\[\w{5}\] \[(\d+)\]/', $e->getMessage(), $matches) === 1 ? (int) $matches[1] : null;
    }

    public function active(): bool
    {
        try {
            $until = $this->store()->get(self::KEY);
        } catch (Throwable) {
            return false;
        }

        return is_int($until) && CarbonImmutable::now()->getTimestamp() < $until;
    }

    /**
     * Throws the way a failing query does while the marker is set, so callers take their outage path.
     *
     * @throws PDOException
     */
    public function guard(): void
    {
        if ($this->active()) {
            throw new PDOException('The database failed moments ago; not retrying yet.');
        }
    }

    private function store(): Repository
    {
        return Cache::store('status_pages');
    }
}
