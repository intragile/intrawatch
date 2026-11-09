<?php

namespace Tests\Unit\Support;

use Amp\Http\Server\Driver\Internal\AbstractHttpDriver;
use Amp\Http\Server\Driver\Internal\TimeoutQueue;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\Support\HttpFixtureServer;
use Tests\Support\RdapFixtureServer;

use function Amp\delay;

/**
 * amphp's HTTP/1 driver closes idle connections through one process-wide timeout queue whose clock is a cached
 * time(), refreshed by a one-second timer that only fires while the event loop runs. A test run can leave the
 * loop idle for longer than the 15 s connection timeout; a connection accepted before the timer caught up was
 * given a deadline already in the past and closed by that timer, before its request was read. The clients saw "Connection closed before the response was complete", or a lookup that failed.
 */
final class FixtureServerIdleLoopTest extends TestCase
{
    private static function queue(): ?TimeoutQueue
    {
        $queue = (new ReflectionProperty(AbstractHttpDriver::class, 'timeoutQueue'))->getValue();

        return $queue instanceof TimeoutQueue ? $queue : null;
    }

    /**
     * Leaves the shared queue's clock as a long idle event loop does: a minute behind.
     */
    private function idleLoop(): void
    {
        if (self::queue() === null) {
            // No HTTP/1 connection was served in this process yet; serve one so the queue exists.
            $server = new HttpFixtureServer;
            $this->assertStringContainsString("\r\n\r\nok", $this->get($server->port(), '/fast'));
            $server->stop();
        }
        $queue = self::queue();
        $this->assertNotNull($queue);
        (new ReflectionProperty(TimeoutQueue::class, 'now'))->setValue($queue, time() - 60);
    }

    /**
     * Connects and holds the connection for longer than the queue's one-second timer before sending the request,
     * so a deadline set from a stale clock has passed by then; returns the raw response.
     */
    private function get(int $port, string $path, float $hold = 0.0): string
    {
        $socket = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 5);
        $this->assertIsResource($socket, "connect failed ({$errno}): ".($error ?? 'no message'));
        delay($hold);
        fwrite($socket, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        stream_set_blocking($socket, false);

        $response = '';
        for ($i = 0; $i < 100 && ! feof($socket); $i++) {
            delay(0.01);
            $response .= (string) fread($socket, 65536);
        }
        fclose($socket);

        return $response;
    }

    public function test_the_http_fixture_serves_after_the_loop_was_idle(): void
    {
        $this->idleLoop();
        $server = new HttpFixtureServer;
        try {
            $this->assertStringContainsString("\r\n\r\nok", $this->get($server->port(), '/fast', hold: 1.2));
        } finally {
            $server->stop();
        }
    }

    public function test_the_rdap_fixture_serves_after_the_loop_was_idle(): void
    {
        $this->idleLoop();
        $server = new RdapFixtureServer;
        try {
            $server->respond('/domain/example.com', 200, ['ok' => true]);
            $this->assertStringStartsWith('HTTP/1.1 200', $this->get($server->port(), '/domain/example.com', hold: 1.2));
        } finally {
            $server->stop();
        }
    }
}
