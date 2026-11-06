<?php

namespace App\Monitoring\Checks;

use Amp\ByteStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\Dns\DnsResolver;
use Amp\Process\Process;
use Amp\Socket\ConnectException;
use Amp\TimeoutCancellation;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Net\AllowedAddressResolver;
use App\Monitoring\Net\BlockedTarget;
use App\Monitoring\Net\NetworkPolicy;
use Throwable;

use function Amp\async;

/**
 * Sends ICMP echo requests through the system ping binary, after applying the network policy to the target.
 */
final class PingChecker implements Checker
{
    private readonly AllowedAddressResolver $addresses;

    private const GRACE_SECONDS = 1;

    private ?string $override;

    private ?string $binary = null;

    private bool $binaryLookedUp = false;

    /**
     * @param  string|null  $binary  Path of the ping executable; located on the system when omitted.
     */
    public function __construct(DnsResolver $resolver, NetworkPolicy $policy, ?string $binary = null)
    {
        $this->override = $binary;
        $this->addresses = new AllowedAddressResolver($resolver, $policy);
    }

    public function check(MonitorSpec $spec, Cancellation $cancellation): CheckOutcome
    {
        $host = is_string($spec->config['host'] ?? null) ? $spec->config['host'] : '';
        $count = is_int($spec->config['count'] ?? null) ? max(1, $spec->config['count']) : 1;
        // Ping gets a second beyond its own deadline so it can print its summary before the check is cut off.
        $limit = new CompositeCancellation($cancellation, new TimeoutCancellation($spec->timeoutSeconds + self::GRACE_SECONDS));

        $process = null;
        $output = '';
        try {
            $ip = $this->addresses->resolve($host, null, $limit)[0];

            $binary = $this->findBinary($ip);
            if ($binary === null) {
                return CheckOutcome::down('Ping is not available');
            }

            $process = Process::start([$binary, ...$this->arguments($binary, $count, $spec->timeoutSeconds, $ip)], cancellation: $limit);
            // Standard error is drained so a chatty ping can never block on a full pipe.
            async(static fn (): string => ByteStream\buffer($process->getStderr(), $limit))->ignore();
            while (($chunk = $process->getStdout()->read($limit)) !== null) {
                $output .= $chunk;
            }
            $process->join($limit);

            return $this->interpret(PingOutput::fromSummary($output), $count);
        } catch (CancelledException) {
            $cancellation->throwIfRequested();

            // Replies that arrived before the deadline still prove the host is reachable.
            $partial = PingOutput::fromReplies($output);
            if ($partial !== null) {
                return $this->interpret($partial, $count);
            }

            return CheckOutcome::down("Timed out after {$spec->timeoutSeconds} s");
        } catch (BlockedTarget) {
            return CheckOutcome::down('Blocked target');
        } catch (ConnectException) {
            return CheckOutcome::down('Connection failed: DNS resolution failed');
        } catch (Throwable $e) {
            return CheckOutcome::fromThrowable($e);
        } finally {
            $process?->kill();
        }
    }

    /**
     * @return list<string>
     */
    private function arguments(string $binary, int $count, int $timeoutSeconds, string $ip): array
    {
        $arguments = ['-n', '-c', (string) $count];
        // The macOS ping6 has no timeout flag; the check's own deadline stops it.
        if (basename($binary) !== 'ping6') {
            array_push($arguments, PHP_OS_FAMILY === 'Darwin' ? '-t' : '-W', (string) $timeoutSeconds);
        }

        return [...$arguments, $ip];
    }

    private function interpret(?PingOutput $output, int $count): CheckOutcome
    {
        if ($output === null) {
            return CheckOutcome::down('Ping failed');
        }

        $lost = max(0, $count - $output->received);
        if ($output->received === 0) {
            return CheckOutcome::down("{$lost}/{$count} packets lost", facts: ['packets_lost' => $lost]);
        }

        return CheckOutcome::up(
            $output->averageMs === null ? null : (int) round($output->averageMs),
            facts: ['packets_lost' => $lost],
        );
    }

    private function findBinary(string $ip): ?string
    {
        if ($this->override !== null) {
            return $this->override;
        }

        // IPv6 targets need the dedicated binary on macOS; Linux ping handles both families.
        if (str_contains($ip, ':') && PHP_OS_FAMILY === 'Darwin') {
            return $this->locate('ping6');
        }

        if (! $this->binaryLookedUp) {
            $this->binary = $this->locate('ping');
            $this->binaryLookedUp = true;
        }

        return $this->binary;
    }

    private function locate(string $name): ?string
    {
        $directories = array_merge(explode(PATH_SEPARATOR, (string) getenv('PATH')), ['/sbin', '/usr/sbin', '/bin', '/usr/bin']);
        foreach ($directories as $directory) {
            $path = rtrim($directory, '/')."/{$name}";
            if ($directory !== '' && is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
