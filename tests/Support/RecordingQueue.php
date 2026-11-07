<?php

namespace Tests\Support;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\NullQueue;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A queue that keeps what is pushed, with the database transaction level at push time, or refuses every push.
 */
final class RecordingQueue extends NullQueue
{
    /** @var list<array{0: object, 1: int}> */
    public static array $pushed = [];

    public function __construct(private readonly bool $failing = false) {}

    /**
     * Registers the queue as connection $name and makes it the default.
     */
    public static function install(string $name, bool $failing = false): void
    {
        self::$pushed = [];
        app('queue')->extend($name, fn (): ConnectorInterface => new class($failing) implements ConnectorInterface
        {
            public function __construct(private readonly bool $failing) {}

            /**
             * @param  array<string, mixed>  $config
             */
            public function connect(array $config): QueueContract
            {
                return new RecordingQueue($this->failing);
            }
        });
        config(["queue.connections.{$name}" => ['driver' => $name], 'queue.default' => $name]);
    }

    /**
     * @param  object|string  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     */
    public function push($job, $data = '', $queue = null): mixed
    {
        if ($this->failing) {
            throw new RuntimeException('queue unavailable');
        }
        if (is_object($job)) {
            self::$pushed[] = [$job, DB::transactionLevel()];
        }

        return null;
    }
}
