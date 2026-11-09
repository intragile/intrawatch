<?php

namespace Tests\Support;

use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\SocketHttpServer;
use Psr\Log\NullLogger;

/**
 * The amphp HTTP server behind the scripted fixture servers.
 *
 * amphp closes idle connections through one process-wide timeout queue whose clock is a cached time(), refreshed
 * by a timer that only fires while the event loop runs. Between tests the loop can sit idle for longer than the
 * default 15 s stream timeout; a connection accepted before that timer caught up got a deadline already in the
 * past and was closed before its request was read. The idle timeouts here outlast any test run, so a stale clock
 * cannot expire a fresh connection; the fixtures close their connections when they stop.
 */
final class FixtureHttpServer
{
    private const IDLE_TIMEOUT = 86_400;

    public static function create(): SocketHttpServer
    {
        $logger = new NullLogger;

        return SocketHttpServer::createForDirectAccess(
            $logger,
            enableCompression: false,
            connectionLimitPerIp: 1000,
            httpDriverFactory: new DefaultHttpDriverFactory($logger, streamTimeout: self::IDLE_TIMEOUT, connectionTimeout: self::IDLE_TIMEOUT),
        );
    }
}
