<?php

namespace App\Monitoring\Net;

use Carbon\CarbonImmutable;
use OpenSSLCertificate;

/**
 * The parts of an X.509 certificate that the TLS checks report and reason about.
 */
final readonly class PeerCertificate
{
    /**
     * @param  list<string>  $dnsNames  DNS names from the subjectAltName extension, lowercase.
     * @param  list<string>  $ipAddresses  IP addresses from the subjectAltName extension, in packed binary form.
     */
    private function __construct(
        public string $subject,
        public string $issuer,
        public ?string $commonName,
        public array $dnsNames,
        public array $ipAddresses,
        public int $notBefore,
        public int $notAfter,
        public string $fingerprint,
        public bool $isCa,
        public bool $hasCaIssuersUrl,
    ) {}

    public static function fromPem(string $pem): ?self
    {
        $certificate = @openssl_x509_read($pem);

        return $certificate === false ? null : self::fromCertificate($certificate);
    }

    public static function fromCertificate(OpenSSLCertificate $certificate): ?self
    {
        $info = openssl_x509_parse($certificate, false);
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
        if (! is_array($info) || ! is_string($fingerprint)) {
            return null;
        }

        $subject = is_array($info['subject'] ?? null) ? $info['subject'] : [];
        $issuer = is_array($info['issuer'] ?? null) ? $info['issuer'] : [];
        $extensions = is_array($info['extensions'] ?? null) ? $info['extensions'] : [];
        [$dnsNames, $ipAddresses] = self::alternativeNames(is_string($extensions['subjectAltName'] ?? null) ? $extensions['subjectAltName'] : '');
        $commonName = $subject['commonName'] ?? null;
        $commonName = is_array($commonName) ? end($commonName) : $commonName;
        $constraints = is_string($extensions['basicConstraints'] ?? null) ? $extensions['basicConstraints'] : '';

        return new self(
            subject: self::distinguishedName($subject),
            issuer: self::distinguishedName($issuer),
            commonName: is_string($commonName) ? $commonName : null,
            dnsNames: $dnsNames,
            ipAddresses: $ipAddresses,
            notBefore: is_int($info['validFrom_time_t'] ?? null) ? $info['validFrom_time_t'] : 0,
            notAfter: is_int($info['validTo_time_t'] ?? null) ? $info['validTo_time_t'] : 0,
            fingerprint: strtolower($fingerprint),
            isCa: stripos($constraints, 'CA:TRUE') !== false,
            hasCaIssuersUrl: is_string($extensions['authorityInfoAccess'] ?? null) && stripos($extensions['authorityInfoAccess'], 'CA Issuers') !== false,
        );
    }

    /**
     * Whole days until notAfter, rounded down; negative once the certificate has expired.
     */
    public function daysLeft(CarbonImmutable $now): int
    {
        return (int) floor(($this->notAfter - $now->getTimestamp()) / 86400);
    }

    public function notAfterIso(): string
    {
        return CarbonImmutable::createFromTimestampUTC($this->notAfter)->toIso8601ZuluString();
    }

    public function isExpiredAt(CarbonImmutable $now): bool
    {
        return $now->getTimestamp() > $this->notAfter;
    }

    public function isNotYetValidAt(CarbonImmutable $now): bool
    {
        return $now->getTimestamp() < $this->notBefore;
    }

    /**
     * Whether the issuer and the subject are the same name, as for a self-signed certificate.
     */
    public function isSelfIssued(): bool
    {
        return $this->subject === $this->issuer;
    }

    /**
     * Whether the certificate is valid for the host, following RFC 6125: subjectAltName entries when there are
     * any, the common name otherwise, and IP addresses only against IP entries.
     */
    public function matchesHost(string $host): bool
    {
        $host = trim($host, '[]');
        $packed = @inet_pton($host);

        if ($packed !== false) {
            return in_array($packed, $this->ipAddresses, true)
                || ($this->dnsNames === [] && $this->ipAddresses === [] && $this->commonName !== null && @inet_pton($this->commonName) === $packed);
        }

        $names = $this->dnsNames !== [] || $this->ipAddresses !== [] ? $this->dnsNames : array_filter([$this->commonName]);
        foreach ($names as $name) {
            if (self::nameMatches($name, $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compares one presented DNS name with a host. A wildcard is accepted only as the complete left-most label
     * and covers exactly one label, and only beneath at least two further labels.
     */
    public static function nameMatches(string $pattern, string $host): bool
    {
        $pattern = rtrim(strtolower($pattern), '.');
        $host = rtrim(strtolower($host), '.');
        if ($pattern === '' || $host === '') {
            return false;
        }

        if (! str_contains($pattern, '*')) {
            return $pattern === $host;
        }

        $patternLabels = explode('.', $pattern);
        $hostLabels = explode('.', $host);
        if ($patternLabels[0] !== '*' || count($patternLabels) < 3 || count($patternLabels) !== count($hostLabels) || $hostLabels[0] === '') {
            return false;
        }

        $patternRest = array_slice($patternLabels, 1);
        if (in_array('', $patternRest, true) || str_contains(implode('.', $patternRest), '*')) {
            return false;
        }

        return $patternRest === array_slice($hostLabels, 1);
    }

    /**
     * @param  array<array-key, mixed>  $parts
     */
    private static function distinguishedName(array $parts): string
    {
        $short = ['commonName' => 'CN', 'organizationName' => 'O', 'organizationalUnitName' => 'OU', 'countryName' => 'C', 'stateOrProvinceName' => 'ST', 'localityName' => 'L'];
        $pairs = [];

        foreach ($parts as $key => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if (is_scalar($item)) {
                    $pairs[] = ($short[$key] ?? $key).'='.$item;
                }
            }
        }

        return implode(', ', $pairs);
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function alternativeNames(string $extension): array
    {
        $dns = [];
        $ips = [];

        foreach (array_map(trim(...), explode(',', $extension)) as $entry) {
            if (str_starts_with($entry, 'DNS:')) {
                $dns[] = strtolower(substr($entry, 4));
            } elseif (str_starts_with($entry, 'IP Address:')) {
                $packed = @inet_pton(trim(substr($entry, 11)));
                if ($packed !== false) {
                    $ips[] = $packed;
                }
            }
        }

        return [$dns, $ips];
    }
}
