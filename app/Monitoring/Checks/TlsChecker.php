<?php

namespace App\Monitoring\Checks;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Socket\ConnectException;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Net\BlockedTarget;
use App\Monitoring\Net\TlsFailure;
use App\Monitoring\Net\TlsInspector;
use App\Monitoring\Net\TlsReport;
use Throwable;

/**
 * Checks a server's TLS certificate, optionally after a STARTTLS dialog: down when it does not verify, degraded
 * when it expires soon, a deprecated protocol is accepted or a weak cipher is negotiated.
 */
final class TlsChecker implements Checker
{
    private const ECONNREFUSED = [111, 61];

    public function __construct(private readonly TlsInspector $inspector) {}

    public function check(MonitorSpec $spec, Cancellation $cancellation): CheckOutcome
    {
        $config = $spec->config;
        $timeout = $spec->timeoutSeconds;
        $servername = $config['servername'] ?? null;
        $checkProtocols = ($config['check_protocols'] ?? true) === true;

        try {
            $report = $this->inspector->inspect(
                is_string($config['host'] ?? null) ? $config['host'] : '',
                is_int($config['port'] ?? null) ? $config['port'] : 443,
                is_string($config['starttls'] ?? null) ? $config['starttls'] : 'none',
                is_string($servername) ? $servername : null,
                $checkProtocols,
                $cancellation,
                (float) $timeout,
            );
        } catch (CancelledException) {
            $cancellation->throwIfRequested();

            return CheckOutcome::down("Timed out after {$timeout} s");
        } catch (BlockedTarget) {
            return CheckOutcome::down('Blocked target');
        } catch (TlsFailure $e) {
            return CheckOutcome::down($e->getMessage());
        } catch (ConnectException $e) {
            return CheckOutcome::down($this->connectFailure($e, $timeout));
        } catch (Throwable $e) {
            return CheckOutcome::fromThrowable($e);
        }

        return $this->evaluate($spec, $report);
    }

    private function evaluate(MonitorSpec $spec, TlsReport $report): CheckOutcome
    {
        $config = $spec->config;
        $facts = [
            'tls.days_left' => $report->daysLeft,
            'tls.not_after' => $report->notAfter,
            'tls.issuer' => $report->issuer,
            'tls.protocol' => $report->protocol,
            'tls.cipher' => $report->cipher,
            'tls.deprecated_protocols' => $report->deprecatedProtocols,
        ];
        $watch = ['tls.expiry_band' => ExpiryThresholds::band($report->daysLeft, ExpiryThresholds::fromConfig($config['expiry_days'] ?? null))];
        if (($config['watch_certificate'] ?? true) === true) {
            $watch['tls.fingerprint'] = $report->fingerprint;
            $watch['tls.issuer'] = $report->issuer;
        }

        if (! $report->valid) {
            return CheckOutcome::down('Certificate '.($report->error ?? TlsReport::UNTRUSTED_CHAIN), $report->latencyMs, $facts, $watch);
        }

        $degradedBelow = is_int($config['degraded_below_days'] ?? null) ? $config['degraded_below_days'] : ExpiryThresholds::DEGRADED_BELOW_DAYS;
        $reasons = [];
        if ($report->daysLeft < $degradedBelow) {
            $reasons[] = ExpiryThresholds::expiresIn('Certificate', $report->daysLeft);
        }
        if ($report->deprecatedProtocols !== null && $report->deprecatedProtocols !== []) {
            $reasons[] = 'Deprecated protocols accepted: '.implode(', ', $report->deprecatedProtocols);
        }
        if (($config['check_ciphers'] ?? true) === true && $report->hasWeakCipher()) {
            $reasons[] = 'Weak cipher negotiated: '.$report->cipher;
        }

        return $reasons === []
            ? CheckOutcome::up($report->latencyMs, '', $facts, $watch)
            : CheckOutcome::degraded(implode('; ', $reasons), $report->latencyMs, $facts, $watch);
    }

    private function connectFailure(ConnectException $e, int $timeout): string
    {
        return match (true) {
            in_array($e->getCode(), self::ECONNREFUSED, true) => 'Connection refused',
            $e->getCode() === 110 || str_contains($e->getMessage(), 'timed out') => "Timed out after {$timeout} s",
            $e->getPrevious() !== null => 'Connection failed: DNS resolution failed',
            default => 'Connection failed',
        };
    }
}
