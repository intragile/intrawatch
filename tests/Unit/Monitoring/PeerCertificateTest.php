<?php

namespace Tests\Unit\Monitoring;

use App\Monitoring\Net\PeerCertificate;
use App\Monitoring\Net\TlsReport;
use PHPUnit\Framework\TestCase;

class PeerCertificateTest extends TestCase
{
    public function test_exact_names_match_case_insensitively_and_ignore_a_trailing_dot(): void
    {
        $this->assertTrue(PeerCertificate::nameMatches('Example.COM', 'example.com.'));
        $this->assertFalse(PeerCertificate::nameMatches('example.com', 'www.example.com'));
    }

    public function test_wildcard_covers_exactly_one_left_most_label(): void
    {
        $this->assertTrue(PeerCertificate::nameMatches('*.example.com', 'www.example.com'));
        $this->assertFalse(PeerCertificate::nameMatches('*.example.com', 'a.b.example.com'));
        $this->assertFalse(PeerCertificate::nameMatches('*.example.com', 'example.com'));
        $this->assertFalse(PeerCertificate::nameMatches('www.*.com', 'www.example.com'));
        $this->assertFalse(PeerCertificate::nameMatches('w*.example.com', 'www.example.com'));
        $this->assertFalse(PeerCertificate::nameMatches('*.com', 'example.com'));
        $this->assertFalse(PeerCertificate::nameMatches('*', 'localhost'));
    }

    public function test_weak_ciphers(): void
    {
        $this->assertFalse(TlsReport::isWeakCipher('TLS_AES_256_GCM_SHA384', 'TLSv1.3'));
        $this->assertFalse(TlsReport::isWeakCipher('ECDHE-RSA-AES128-GCM-SHA256', 'TLSv1.2'));
        $this->assertFalse(TlsReport::isWeakCipher('ECDHE-ECDSA-CHACHA20-POLY1305', 'TLSv1.2'));
        $this->assertTrue(TlsReport::isWeakCipher('ECDHE-RSA-AES128-SHA', 'TLSv1.2'));
        $this->assertTrue(TlsReport::isWeakCipher('DES-CBC3-SHA', 'TLSv1.2'));
        $this->assertTrue(TlsReport::isWeakCipher('RC4-MD5', 'TLSv1'));
        $this->assertTrue(TlsReport::isWeakCipher('ADH-AES128-GCM-SHA256-anon', 'TLSv1.2'));
    }
}
