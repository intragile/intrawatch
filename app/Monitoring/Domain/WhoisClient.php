<?php

namespace App\Monitoring\Domain;

use Amp\Cancellation;
use Amp\Socket\ConnectContext;
use App\Monitoring\Net\GuardedConnector;

/**
 * Sends one query to a WHOIS server (RFC 3912) through the guarded connector and reads the answer.
 */
final class WhoisClient
{
    /** Bytes of an answer that are kept; the rest is not read. */
    public const RESPONSE_LIMIT = 65536;

    /**
     * @param  int  $port  The port used for servers named without one.
     */
    public function __construct(
        private readonly GuardedConnector $connector,
        private readonly int $port = 43,
    ) {}

    /**
     * @param  string  $server  `host` or `host:port`.
     */
    public function query(string $server, string $query, Cancellation $cancellation): string
    {
        $target = preg_match('/:\d+$/', $server) === 1 ? $server : "{$server}:{$this->port}";
        $socket = $this->connector->connect($target, new ConnectContext, $cancellation);

        try {
            $socket->write($query."\r\n");

            $answer = '';
            while (strlen($answer) < self::RESPONSE_LIMIT && ($chunk = $socket->read($cancellation)) !== null) {
                $answer .= $chunk;
            }

            return substr($answer, 0, self::RESPONSE_LIMIT);
        } finally {
            $socket->close();
        }
    }
}
