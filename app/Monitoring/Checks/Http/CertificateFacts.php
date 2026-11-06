<?php

namespace App\Monitoring\Checks\Http;

use Amp\Cancellation;
use Amp\Socket\TlsInfo;
use App\Monitoring\Checks\ExpiryThresholds;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Net\PeerCertificate;
use App\Monitoring\Worker\Clock;

/**
 * Certificate facts for https checks, read from the connection the request itself used.
 *
 * The expiry band and the certificate's fingerprint and issuer are always watched. The certificate only
 * degrades the check when it expires within seven days; protocol and cipher stay facts.
 */
final class CertificateFacts implements TlsFactsProvider
{
    public function __construct(private readonly Clock $clock) {}

    #[\Override]
    public function inspect(MonitorSpec $spec, string $host, TlsInfo $tls, Cancellation $cancellation): array
    {
        $certificates = $tls->getPeerCertificates();
        $pem = isset($certificates[0]) ? $certificates[0]->toPem() : null;
        $leaf = is_string($pem) ? PeerCertificate::fromPem($pem) : null;
        if ($leaf === null) {
            return ['facts' => [], 'watch' => [], 'degrade' => null];
        }

        $daysLeft = $leaf->daysLeft($this->clock->now());

        return [
            'facts' => [
                'tls.days_left' => $daysLeft,
                'tls.not_after' => $leaf->notAfterIso(),
                'tls.issuer' => $leaf->issuer,
                'tls.protocol' => $tls->getVersion(),
                'tls.cipher' => $tls->getCipherName(),
            ],
            'watch' => [
                'tls.expiry_band' => ExpiryThresholds::band($daysLeft, ExpiryThresholds::fromConfig($spec->config['tls_expiry_days'] ?? null)),
                'tls.fingerprint' => $leaf->fingerprint,
                'tls.issuer' => $leaf->issuer,
            ],
            'degrade' => $daysLeft < ExpiryThresholds::DEGRADED_BELOW_DAYS ? ExpiryThresholds::expiresIn('Certificate', $daysLeft) : null,
        ];
    }
}
