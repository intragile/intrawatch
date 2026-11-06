<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Generates a throwaway certificate authority, a server certificate and a client certificate for TLS tests.
 *
 * Files live in a private temporary directory that is removed by cleanup().
 */
final class TestCertificates
{
    public readonly string $caFile;

    /** Server certificate and key in one PEM file. */
    public readonly string $serverFile;

    public readonly string $clientCert;

    public readonly string $clientKey;

    private readonly string $directory;

    /**
     * @param  list<string>  $serverNames  DNS names the server certificate is issued for.
     * @param  int  $days  Validity of the server certificate.
     */
    public function __construct(array $serverNames = ['secure.test'], int $days = 2)
    {
        $directory = sys_get_temp_dir().'/intrawatch-certs-'.bin2hex(random_bytes(6));
        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create the certificate directory.');
        }
        $this->directory = $directory;

        $config = $directory.'/openssl.cnf';
        $altNames = implode(',', array_map(static fn (string $name): string => 'DNS:'.$name, $serverNames));
        file_put_contents($config, <<<CNF
            [req]
            distinguished_name = dn
            [dn]
            [ca_ext]
            basicConstraints = critical,CA:TRUE
            keyUsage = critical,keyCertSign,cRLSign
            subjectKeyIdentifier = hash
            [server_ext]
            basicConstraints = CA:FALSE
            keyUsage = critical,digitalSignature,keyEncipherment
            extendedKeyUsage = serverAuth
            subjectAltName = {$altNames}
            [client_ext]
            basicConstraints = CA:FALSE
            keyUsage = critical,digitalSignature,keyEncipherment
            extendedKeyUsage = clientAuth
            CNF);

        [$caCert, $caKey] = $this->issue(['commonName' => 'IntraWatch Test CA'], $config, 'ca_ext', null, null, 2);
        [$serverCert, $serverKey] = $this->issue(['commonName' => $serverNames[0]], $config, 'server_ext', $caCert, $caKey, $days);
        [$clientCert, $clientKey] = $this->issue(['commonName' => 'intrawatch-client'], $config, 'client_ext', $caCert, $caKey, 2);

        $this->caFile = $this->write('ca.pem', $this->exportCert($caCert));
        $this->serverFile = $this->write('server.pem', $this->exportCert($serverCert).$this->exportKey($serverKey));
        $this->clientCert = $this->exportCert($clientCert);
        $this->clientKey = $this->exportKey($clientKey);
    }

    public function cleanup(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    /**
     * @param  array<string, string>  $subject
     * @return array{0: \OpenSSLCertificate, 1: \OpenSSLAsymmetricKey}
     */
    private function issue(array $subject, string $config, string $extensions, ?\OpenSSLCertificate $caCert, ?\OpenSSLAsymmetricKey $caKey, int $days): array
    {
        $options = ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => $extensions];

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (! $key instanceof \OpenSSLAsymmetricKey) {
            throw new RuntimeException('Cannot create a private key.');
        }

        // openssl_csr_new() takes the key by reference; pass a copy so $key keeps its type.
        $csrKey = $key;
        $csr = openssl_csr_new($subject, $csrKey, $options);
        if (! $csr instanceof \OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('Cannot create a signing request.');
        }

        $cert = openssl_csr_sign($csr, $caCert, $caKey ?? $key, $days, $options, random_int(1, PHP_INT_MAX));
        if ($cert === false) {
            throw new RuntimeException('Cannot sign the certificate.');
        }

        return [$cert, $key];
    }

    private function exportCert(\OpenSSLCertificate $cert): string
    {
        openssl_x509_export($cert, $pem);

        return is_string($pem) ? $pem : throw new RuntimeException('Cannot export the certificate.');
    }

    private function exportKey(\OpenSSLAsymmetricKey $key): string
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
