<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Certificates for TLS check tests, generated with openssl_* into a private temporary directory.
 *
 * A root CA and an intermediate CA issue the leaves; every leaf is written as one PEM file with its key, ready
 * to be served. The set is generated once per test run through shared() and removed by release().
 */
final class TlsFixtures
{
    /** The root CA, the only certificate clients trust. */
    public readonly string $caFile;

    /** secure.test, issued by the root, valid for 90 days. */
    public readonly string $valid;

    /** secure.test with a new key, issued by the root: the same name as $valid, a different fingerprint. */
    public readonly string $renewed;

    /** secure.test, issued by the root, valid for 3 days. */
    public readonly string $expiring;

    /** secure.test, issued by the root, already expired. */
    public readonly string $expired;

    /** other.test, issued by the root. */
    public readonly string $wrongHost;

    /** *.wild.test, issued by the root. */
    public readonly string $wildcard;

    /** secure.test, issued by the intermediate and served without it. */
    public readonly string $missingIntermediate;

    /** The same leaf as $missingIntermediate, served together with the intermediate. */
    public readonly string $withIntermediate;

    /** secure.test, signed by its own key. */
    public readonly string $selfSigned;

    /** PEM of the $valid leaf, for fingerprint assertions. */
    public readonly string $validCertificate;

    /** PEM of the $renewed leaf. */
    public readonly string $renewedCertificate;

    private static ?self $shared = null;

    private readonly string $directory;

    private readonly string $config;

    public static function shared(): self
    {
        return self::$shared ??= new self;
    }

    public static function release(): void
    {
        self::$shared?->cleanup();
        self::$shared = null;
    }

    private function __construct()
    {
        $directory = sys_get_temp_dir().'/intrawatch-tls-'.bin2hex(random_bytes(6));
        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create the certificate directory.');
        }
        $this->directory = $directory;
        $this->config = $this->write('openssl.cnf', <<<'CNF'
            [req]
            distinguished_name = dn
            [dn]
            [ca_ext]
            basicConstraints = critical,CA:TRUE
            keyUsage = critical,keyCertSign,cRLSign
            subjectKeyIdentifier = hash
            [secure_ext]
            basicConstraints = CA:FALSE
            keyUsage = critical,digitalSignature,keyEncipherment
            extendedKeyUsage = serverAuth
            subjectAltName = DNS:secure.test
            [other_ext]
            basicConstraints = CA:FALSE
            keyUsage = critical,digitalSignature,keyEncipherment
            extendedKeyUsage = serverAuth
            subjectAltName = DNS:other.test
            [wild_ext]
            basicConstraints = CA:FALSE
            keyUsage = critical,digitalSignature,keyEncipherment
            extendedKeyUsage = serverAuth
            subjectAltName = DNS:*.wild.test
            CNF);

        try {
            [$root, $rootKey] = $this->issue('IntraWatch Test Root', 'ca_ext', 3650, null, null);
            [$intermediate, $intermediateKey] = $this->issue('IntraWatch Test Intermediate', 'ca_ext', 3650, $root, $rootKey);
            // Zero days of validity: notAfter is the moment of signing, which has passed once the set is complete.
            [$expired, $expiredKey] = $this->issue('secure.test', 'secure_ext', 0, $root, $rootKey);

            $this->caFile = $this->write('ca.pem', $this->pem($root));
            [$valid, $validKey] = $this->issue('secure.test', 'secure_ext', 90, $root, $rootKey);
            [$renewed, $renewedKey] = $this->issue('secure.test', 'secure_ext', 90, $root, $rootKey);
            [$expiring, $expiringKey] = $this->issue('secure.test', 'secure_ext', 3, $root, $rootKey);
            [$wrongHost, $wrongHostKey] = $this->issue('other.test', 'other_ext', 90, $root, $rootKey);
            [$wildcard, $wildcardKey] = $this->issue('*.wild.test', 'wild_ext', 90, $root, $rootKey);
            [$chained, $chainedKey] = $this->issue('secure.test', 'secure_ext', 90, $intermediate, $intermediateKey);
            [$selfSigned, $selfSignedKey] = $this->issue('secure.test', 'secure_ext', 90, null, null);

            $this->validCertificate = $this->pem($valid);
            $this->renewedCertificate = $this->pem($renewed);
            $this->valid = $this->write('valid.pem', $this->validCertificate.$this->key($validKey));
            $this->renewed = $this->write('renewed.pem', $this->renewedCertificate.$this->key($renewedKey));
            $this->expiring = $this->write('expiring.pem', $this->pem($expiring).$this->key($expiringKey));
            $this->expired = $this->write('expired.pem', $this->pem($expired).$this->key($expiredKey));
            $this->wrongHost = $this->write('wrong-host.pem', $this->pem($wrongHost).$this->key($wrongHostKey));
            $this->wildcard = $this->write('wildcard.pem', $this->pem($wildcard).$this->key($wildcardKey));
            $this->missingIntermediate = $this->write('missing-intermediate.pem', $this->pem($chained).$this->key($chainedKey));
            $this->withIntermediate = $this->write('with-intermediate.pem', $this->pem($chained).$this->pem($intermediate).$this->key($chainedKey));
            $this->selfSigned = $this->write('self-signed.pem', $this->pem($selfSigned).$this->key($selfSignedKey));

            $notAfter = openssl_x509_parse($expired)['validTo_time_t'] ?? time();
            while (time() <= $notAfter) {
                usleep(50_000);
            }
        } catch (\Throwable $e) {
            $this->cleanup();

            throw $e;
        }
    }

    public function cleanup(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    /**
     * @return array{0: \OpenSSLCertificate, 1: \OpenSSLAsymmetricKey}
     */
    private function issue(string $commonName, string $extensions, int $days, ?\OpenSSLCertificate $caCert, ?\OpenSSLAsymmetricKey $caKey): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (! $key instanceof \OpenSSLAsymmetricKey) {
            throw new RuntimeException('Cannot create a private key.');
        }

        return [$this->sign($commonName, $extensions, $days, $key, $caCert, $caKey), $key];
    }

    private function sign(string $commonName, string $extensions, int $days, \OpenSSLAsymmetricKey $key, ?\OpenSSLCertificate $caCert, ?\OpenSSLAsymmetricKey $caKey): \OpenSSLCertificate
    {
        $options = ['config' => $this->config, 'digest_alg' => 'sha256', 'x509_extensions' => $extensions];

        // openssl_csr_new() takes the key by reference; pass a copy so $key keeps its type.
        $csrKey = $key;
        $csr = openssl_csr_new(['commonName' => $commonName], $csrKey, $options);
        if (! $csr instanceof \OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('Cannot create a signing request.');
        }

        $cert = openssl_csr_sign($csr, $caCert, $caKey ?? $key, $days, $options, random_int(1, PHP_INT_MAX));
        if ($cert === false) {
            throw new RuntimeException('Cannot sign the certificate.');
        }

        return $cert;
    }

    private function pem(\OpenSSLCertificate $cert): string
    {
        openssl_x509_export($cert, $pem);

        return is_string($pem) ? $pem : throw new RuntimeException('Cannot export the certificate.');
    }

    private function key(\OpenSSLAsymmetricKey $key): string
    {
        openssl_pkey_export($key, $pem);

        return is_string($pem) ? $pem : throw new RuntimeException('Cannot export the private key.');
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->directory.'/'.$name;
        file_put_contents($path, $contents);
        chmod($path, 0600);

        return $path;
    }
}
