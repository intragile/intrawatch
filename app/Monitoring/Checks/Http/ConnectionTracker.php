<?php

namespace App\Monitoring\Checks\Http;

use Amp\Cancellation;
use Amp\Http\Client\Connection\Connection;
use Amp\Http\Client\Connection\ConnectionFactory;
use Amp\Http\Client\Connection\Stream;
use Amp\Http\Client\NetworkInterceptor;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Socket\TlsInfo;

/**
 * Records every connection one check opens, so all of them can be closed when the check ends, and remembers
 * the TLS session of the most recent request, which after redirects is the final hop.
 */
final class ConnectionTracker implements ConnectionFactory, NetworkInterceptor
{
    /** @var list<Connection> */
    private array $connections = [];

    private ?TlsInfo $lastTls = null;

    public function __construct(private readonly ConnectionFactory $factory) {}

    #[\Override]
    public function create(Request $request, Cancellation $cancellation): Connection
    {
        $connection = $this->factory->create($request, $cancellation);
        $this->connections[] = $connection;

        return $connection;
    }

    #[\Override]
    public function requestViaNetwork(Request $request, Cancellation $cancellation, Stream $stream): Response
    {
        $this->lastTls = $stream->getTlsInfo();

        return $stream->request($request, $cancellation);
    }

    public function lastTls(): ?TlsInfo
    {
        return $this->lastTls;
    }

    public function closeAll(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->connections = [];
    }
}
