<?php

namespace App\Monitoring\Checks;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\Socket\ConnectContext;
use Amp\Socket\ConnectException;
use Amp\TimeoutCancellation;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Net\BlockedTarget;
use App\Monitoring\Net\GuardedConnector;
use Throwable;

/**
 * Opens a TCP connection and reports how long the handshake took.
 */
final class TcpChecker implements Checker
{
    private const ECONNREFUSED = [111, 61];

    private const ETIMEDOUT = 110;

    /** Reasons for connect errors, by errno on Linux and macOS. */
    private const REASONS = [
        101 => 'Network unreachable', 51 => 'Network unreachable',
        113 => 'No route to host', 65 => 'No route to host',
        104 => 'Connection reset', 54 => 'Connection reset',
    ];

    public function __construct(private readonly GuardedConnector $connector) {}

    public function check(MonitorSpec $spec, Cancellation $cancellation): CheckOutcome
    {
        $host = is_string($spec->config['host'] ?? null) ? $spec->config['host'] : '';
        $port = is_int($spec->config['port'] ?? null) ? $spec->config['port'] : 0;
        $started = hrtime(true);

        try {
            $socket = $this->connector->connect(
                "tcp://{$this->bracket($host)}:{$port}",
                (new ConnectContext)->withConnectTimeout((float) $spec->timeoutSeconds),
                new CompositeCancellation($cancellation, new TimeoutCancellation($spec->timeoutSeconds)),
            );
            $latencyMs = (int) round((hrtime(true) - $started) / 1_000_000);
            $socket->close();

            return CheckOutcome::up($latencyMs);
        } catch (CancelledException) {
            $cancellation->throwIfRequested();

            return CheckOutcome::down("Timed out after {$spec->timeoutSeconds} s");
        } catch (BlockedTarget) {
            return CheckOutcome::down('Blocked target');
        } catch (ConnectException $e) {
            return CheckOutcome::down($this->describe($e, $spec->timeoutSeconds));
        } catch (Throwable $e) {
            return CheckOutcome::fromThrowable($e);
        }
    }

    private function describe(ConnectException $e, int $timeoutSeconds): string
    {
        // amphp reports some asynchronous connect failures (for example an unreachable host) with the
        // refused code, so "Connection refused" can occasionally mean the host was unreachable.
        $code = $e->getCode();

        return match (true) {
            in_array($code, self::ECONNREFUSED, true) => 'Connection refused',
            $code === self::ETIMEDOUT => "Timed out after {$timeoutSeconds} s",
            $e->getPrevious() !== null => 'Connection failed: DNS resolution failed',
            isset(self::REASONS[$code]) => 'Connection failed: '.self::REASONS[$code],
            default => 'Connection failed: error #'.$code,
        };
    }

    private function bracket(string $host): string
    {
        return str_contains($host, ':') ? "[{$host}]" : $host;
    }
}
