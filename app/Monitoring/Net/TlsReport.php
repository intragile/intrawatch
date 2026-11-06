<?php

namespace App\Monitoring\Net;

/**
 * What one TLS inspection learned about a server's certificate and the negotiated session.
 */
final readonly class TlsReport
{
    public const EXPIRED = 'expired';

    public const HOSTNAME_MISMATCH = 'hostname_mismatch';

    public const UNTRUSTED_CHAIN = 'untrusted_chain';

    public const SELF_SIGNED = 'self_signed';

    public const INCOMPLETE_CHAIN = 'incomplete_chain';

    private const WEAK_CIPHER_MARKERS = ['RC4', '3DES', 'DES-', 'NULL', 'EXPORT', 'MD5', 'anon', 'ADH-', 'AECDH-'];

    private const AEAD_MARKERS = ['GCM', 'CHACHA20', 'CCM'];

    /**
     * @param  string|null  $error  Why verification failed: one of the error constants; null when valid.
     * @param  string  $notAfter  ISO 8601 expiry of the leaf certificate.
     * @param  list<string>  $names  DNS names and the common name the leaf certificate presents.
     * @param  list<string>|null  $deprecatedProtocols  Deprecated protocol versions the server accepted, such as
     *                                                  TLSv1.0; null when the probes were skipped or ran out of time.
     * @param  int  $latencyMs  Duration of the first connection, from connect to the end of the handshake.
     */
    public function __construct(
        public bool $valid,
        public ?string $error,
        public string $notAfter,
        public int $daysLeft,
        public string $issuer,
        public string $subject,
        public array $names,
        public string $fingerprint,
        public string $protocol,
        public string $cipher,
        public ?array $deprecatedProtocols,
        public int $latencyMs,
    ) {}

    public function hasWeakCipher(): bool
    {
        return self::isWeakCipher($this->cipher, $this->protocol);
    }

    /**
     * Whether a cipher suite is considered weak: a known-broken algorithm, or no AEAD mode below TLS 1.3.
     */
    public static function isWeakCipher(string $cipher, string $protocol): bool
    {
        foreach (self::WEAK_CIPHER_MARKERS as $marker) {
            if (str_contains($cipher, $marker)) {
                return true;
            }
        }

        if ($protocol === 'TLSv1.3') {
            return false;
        }

        foreach (self::AEAD_MARKERS as $marker) {
            if (str_contains(strtoupper($cipher), $marker)) {
                return false;
            }
        }

        return true;
    }
}
