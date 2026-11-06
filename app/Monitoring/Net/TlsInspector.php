<?php

namespace App\Monitoring\Net;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\Future;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\ConnectException;
use Amp\Socket\ResourceSocket;
use Amp\Socket\TlsException;
use Amp\Socket\TlsInfo;
use Amp\TimeoutCancellation;
use App\Monitoring\Worker\Clock;
use Throwable;

use function Amp\async;

/**
 * Connects to a TLS server, verifies its certificate and reports the certificate and session details.
 *
 * Every connection goes through the guarded connector to the resolved address while the configured host, or
 * the servername, stays the peer name and SNI. A failed verification is followed by an unverified handshake to
 * read the certificate, and the reason is decided from the certificate itself rather than from OpenSSL's error
 * text, whose wording differs between builds.
 */
final class TlsInspector
{
    /** Each deprecated-protocol probe gives up after this many seconds and counts as "not offered". */
    private const PROBE_TIMEOUT = 3.0;

    /** Time kept back from the check's deadline for the checker to finish after the probes. */
    private const PROBE_MARGIN = 0.1;

    /** Probes are not started with less time than this left. */
    private const MIN_PROBE_TIME = 0.2;

    /** The connect phase is bounded by the caller's cancellation; this only keeps amphp's own limit out of the way. */
    private const CONNECT_TIMEOUT = 120.0;

    /** Report name => crypto method and the protocol name OpenSSL reports for it. */
    private const DEPRECATED_PROTOCOLS = [
        'TLSv1.0' => [STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT, 'TLSv1'],
        'TLSv1.1' => [STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT, 'TLSv1.1'],
    ];

    public function __construct(
        private readonly GuardedConnector $connector,
        private readonly Clock $clock,
        private readonly ?string $caFile = null,
    ) {}

    /**
     * With $timeoutSeconds, the certificate handshakes end at that deadline while the deprecated-protocol probes
     * only use the time left: a probe that runs out of time is reported as unknown and never fails the
     * inspection. $cancellation then stands for the caller's own cancellation, such as a worker shutdown, and is
     * the only thing that aborts the probes. Without $timeoutSeconds, $cancellation bounds everything.
     *
     * @param  string  $starttls  none, smtp, imap or pop3
     *
     * @throws TlsFailure When no certificate could be read: a failed handshake or STARTTLS dialog.
     * @throws CancelledException
     * @throws BlockedTarget
     * @throws ConnectException
     */
    public function inspect(string $host, int $port, string $starttls, ?string $servername, bool $checkProtocols, Cancellation $cancellation, ?float $timeoutSeconds = null): TlsReport
    {
        $peerName = $servername !== null && $servername !== '' ? $servername : trim($host, '[]');
        $verified = $this->context($peerName, verify: true);
        $started = hrtime(true);
        $error = null;
        $caller = $cancellation;
        if ($timeoutSeconds !== null) {
            $cancellation = new CompositeCancellation($caller, new TimeoutCancellation($timeoutSeconds));
        }

        try {
            $session = $this->handshake($host, $port, $starttls, $verified, null, $cancellation);
            $latencyMs = $this->elapsedMs($started);
        } catch (TlsException) {
            $latencyMs = $this->elapsedMs($started);

            try {
                $session = $this->handshake($host, $port, $starttls, $this->context($peerName, verify: false), null, $cancellation);
            } catch (TlsException) {
                throw TlsFailure::handshake();
            }

            $error = $this->classify($this->chain($session), $peerName);
            if ($error !== TlsReport::EXPIRED) {
                // Confirm the failure, so a connection reset during the first handshake is not reported as a
                // certificate problem.
                try {
                    $session = $this->handshake($host, $port, $starttls, $verified, null, $cancellation);
                    $error = null;
                } catch (TlsException) {
                    $error ??= TlsReport::UNTRUSTED_CHAIN;
                }
            }
        }

        $chain = $this->chain($session);
        $leaf = $chain[0] ?? throw TlsFailure::handshake();

        $names = $leaf->dnsNames;
        if ($leaf->commonName !== null && ! in_array(strtolower($leaf->commonName), $names, true)) {
            $names[] = $leaf->commonName;
        }

        return new TlsReport(
            valid: $error === null,
            error: $error,
            notAfter: $leaf->notAfterIso(),
            daysLeft: $leaf->daysLeft($this->clock->now()),
            issuer: $leaf->issuer,
            subject: $leaf->subject,
            names: $names,
            fingerprint: $leaf->fingerprint,
            protocol: $session->getVersion(),
            cipher: $session->getCipherName(),
            deprecatedProtocols: match (true) {
                ! $checkProtocols => [],
                // The check is down for the certificate anyway; spare the server further connections.
                $error !== null => null,
                default => $this->deprecatedProtocols($host, $port, $starttls, $peerName, $caller, $this->probeBudget($timeoutSeconds, $started)),
            },
            latencyMs: $latencyMs,
        );
    }

    /**
     * Decides from the unverified chain why verification failed, or null when nothing in the certificates
     * explains it.
     *
     * @param  list<PeerCertificate>  $chain  The certificates the server sent, leaf first.
     */
    private function classify(array $chain, string $peerName): ?string
    {
        $leaf = $chain[0] ?? null;
        if ($leaf === null) {
            return null;
        }

        if ($leaf->isExpiredAt($this->clock->now())) {
            return TlsReport::EXPIRED;
        }
        if (! $leaf->matchesHost($peerName)) {
            return TlsReport::HOSTNAME_MISMATCH;
        }
        if ($leaf->isSelfIssued()) {
            return TlsReport::SELF_SIGNED;
        }
        if ($leaf->isNotYetValidAt($this->clock->now())) {
            // There is no code of its own for this; it is closest to a chain that does not verify.
            return TlsReport::UNTRUSTED_CHAIN;
        }

        // Follow issuer links through the certificates that were sent.
        $bySubject = [];
        foreach (array_slice($chain, 1) as $certificate) {
            $bySubject[$certificate->subject] ??= $certificate;
        }
        $top = $leaf;
        $seen = [$leaf->fingerprint => true];
        while (($issuer = $bySubject[$top->issuer] ?? null) !== null && ! isset($seen[$issuer->fingerprint])) {
            $seen[$issuer->fingerprint] = true;
            $top = $issuer;
        }

        if ($top === $leaf && $leaf->hasCaIssuersUrl) {
            // The server sent no issuer for a certificate that names where its issuer is published, as public
            // CA leaves do: the intermediate is missing. Without such a URL an unknown CA is far more likely.
            return TlsReport::INCOMPLETE_CHAIN;
        }

        return TlsReport::UNTRUSTED_CHAIN;
    }

    /**
     * Seconds each probe may take: at most PROBE_TIMEOUT and never beyond the check's deadline; null when too
     * little time is left to probe at all.
     */
    private function probeBudget(?float $timeoutSeconds, int $started): ?float
    {
        if ($timeoutSeconds === null) {
            return self::PROBE_TIMEOUT;
        }

        $remaining = $timeoutSeconds - (hrtime(true) - $started) / 1e9 - self::PROBE_MARGIN;

        return $remaining < self::MIN_PROBE_TIME ? null : min(self::PROBE_TIMEOUT, $remaining);
    }

    /**
     * The deprecated protocols the server accepted, or null when that could not be determined in time.
     *
     * Behind STARTTLS every probe repeats the whole mail dialog, so TLS 1.0 is only tried once TLS 1.1 was
     * accepted; a server that refuses TLS 1.1 is not expected to accept TLS 1.0.
     *
     * @return list<string>|null
     *
     * @throws CancelledException When $cancellation is requested.
     */
    private function deprecatedProtocols(string $host, int $port, string $starttls, string $peerName, Cancellation $cancellation, ?float $budget): ?array
    {
        if ($budget === null) {
            return null;
        }

        if ($starttls === 'none') {
            [$errors, $results] = Future\awaitAll([
                'TLSv1.0' => async(fn (): ?bool => $this->probe('TLSv1.0', $host, $port, $starttls, $peerName, $cancellation, $budget)),
                'TLSv1.1' => async(fn (): ?bool => $this->probe('TLSv1.1', $host, $port, $starttls, $peerName, $cancellation, $budget)),
            ]);
            foreach ($errors as $error) {
                throw $error;
            }
        } else {
            $results = ['TLSv1.1' => $this->probe('TLSv1.1', $host, $port, $starttls, $peerName, $cancellation, $budget)];
            $results['TLSv1.0'] = $results['TLSv1.1'] === true
                ? $this->probe('TLSv1.0', $host, $port, $starttls, $peerName, $cancellation, $budget)
                : false;
        }

        $accepted = [];
        $unknown = false;
        foreach (array_keys(self::DEPRECATED_PROTOCOLS) as $name) {
            $result = $results[$name] ?? null;
            if ($result === true) {
                $accepted[] = $name;
            }
            $unknown = $unknown || $result === null;
        }

        return $accepted === [] && $unknown ? null : $accepted;
    }

    /**
     * One handshake restricted to a deprecated protocol: true when accepted, false when refused, null when it
     * ran out of time.
     */
    private function probe(string $name, string $host, int $port, string $starttls, string $peerName, Cancellation $cancellation, float $budget): ?bool
    {
        [$method, $reported] = self::DEPRECATED_PROTOCOLS[$name];
        $context = $this->context($peerName, verify: false)
            ->withSecurityLevel(0)
            ->withCiphers('DEFAULT:@SECLEVEL=0');

        try {
            $session = $this->handshake($host, $port, $starttls, $context, $method, new CompositeCancellation($cancellation, new TimeoutCancellation($budget)));

            return $session->getVersion() === $reported;
        } catch (CancelledException) {
            $cancellation->throwIfRequested();

            return null;
        } catch (Throwable) {
            // A refusal by either side, including the local OpenSSL, means the protocol is not offered.
            return false;
        }
    }

    private function handshake(string $host, int $port, string $starttls, ClientTlsContext $tls, ?int $cryptoMethod, Cancellation $cancellation): TlsInfo
    {
        $address = (str_contains($host, ':') && ! str_starts_with($host, '[') ? "[{$host}]" : $host).':'.$port;
        $context = (new ConnectContext)->withConnectTimeout(self::CONNECT_TIMEOUT);
        // ClientTlsContext only sets a minimum version; a probe for one exact version needs its own crypto method,
        // so the TLS options are applied to the stream directly rather than through the connect context.
        $socket = $this->connector->connect('tcp://'.$address, $cryptoMethod === null ? $context->withTlsContext($tls) : $context, $cancellation);

        try {
            StartTls::negotiate($socket, $starttls, $cancellation);

            if ($cryptoMethod !== null) {
                $resource = $socket instanceof ResourceSocket ? $socket->getResource() : null;
                $options = $tls->toStreamContextArray()['ssl'] ?? null;
                if (! is_resource($resource) || ! is_array($options)) {
                    throw TlsFailure::handshake();
                }
                $options['crypto_method'] = $cryptoMethod;
                stream_context_set_options($resource, ['ssl' => $options]);
            }

            $socket->setupTls($cancellation);

            return $socket->getTlsInfo() ?? throw TlsFailure::handshake();
        } finally {
            $socket->close();
        }
    }

    private function context(string $peerName, bool $verify): ClientTlsContext
    {
        $context = (new ClientTlsContext($peerName))->withPeerCapturing();
        if ($this->caFile !== null) {
            $context = $context->withCaFile($this->caFile);
        }

        return $verify ? $context->withPeerVerification() : $context->withoutPeerVerification();
    }

    /**
     * @return list<PeerCertificate>
     */
    private function chain(TlsInfo $session): array
    {
        $chain = [];
        foreach ($session->getPeerCertificates() as $certificate) {
            $pem = $certificate->toPem();
            $parsed = is_string($pem) ? PeerCertificate::fromPem($pem) : null;
            if ($parsed !== null) {
                $chain[] = $parsed;
            }
        }

        return $chain;
    }

    private function elapsedMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
