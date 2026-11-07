<?php

namespace App\Status\Pages;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use PDOException;
use Throwable;

/**
 * A short "database down" marker shared by every web process through the status page file cache.
 *
 * When a public request finds the database failing, the marker is set for 30 s. Until it expires, public
 * requests that would need the database fail at once, answering from a cached copy or with a 503, instead of
 * each making its own connection attempt: against an unreachable host every attempt would hold a worker for
 * the whole connect timeout.
 */
final class DatabaseOutage
{
    public const SECONDS = 30;

    private const KEY = 'status:db-down';

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
