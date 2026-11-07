<?php

namespace Tests\Feature\Alerting\Senders;

use App\Alerting\Channels\Senders\SmtpTransportFactory;
use App\Jobs\SendNotification;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Tests\TestCase;

class SmtpTransportFactoryTest extends TestCase
{
    /**
     * @return array<string, array{string, bool, bool}>
     */
    public static function modes(): array
    {
        // encryption, implicit TLS, STARTTLS required
        return ['starttls' => ['starttls', false, true], 'tls' => ['tls', true, false], 'none' => ['none', false, false]];
    }

    public function test_app_mailer_smtp_timeout_stays_below_the_send_job_timeout(): void
    {
        // E-mail channels without an SMTP server of their own send through the application mailer.
        $transport = Mail::mailer('smtp')->getSymfonyTransport();

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertSame(10.0, $stream->getTimeout());
        $this->assertLessThan((new SendNotification(1))->timeout, $stream->getTimeout());
    }

    #[DataProvider('modes')]
    public function test_transport_security_settings(string $encryption, bool $implicit, bool $startTlsRequired): void
    {
        $transport = (new SmtpTransportFactory)->make('93.184.216.34', 'smtp.example.test', 587, $encryption, 'u', 'secret-pw');

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame($startTlsRequired, $transport->isAutoTls());
        $this->assertSame($startTlsRequired, $transport->isTlsRequired());
        $this->assertSame('u', $transport->getUsername());

        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertSame($implicit, $stream->isTLS());
        $this->assertSame('93.184.216.34', $stream->getHost());
        $this->assertSame(10.0, $stream->getTimeout());

        $ssl = $stream->getStreamOptions()['ssl'] ?? [];
        $this->assertIsArray($ssl);
        $this->assertSame('smtp.example.test', $ssl['peer_name']);
        $this->assertTrue($ssl['verify_peer']);
        $this->assertTrue($ssl['verify_peer_name']);
        $this->assertSame(STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT, $ssl['crypto_method']);
    }

    public function test_ipv6_addresses_are_bracketed(): void
    {
        $transport = (new SmtpTransportFactory)->make('2606:2800:220:1::1', 'smtp.example.test', 465, 'tls', null, null);

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertSame('[2606:2800:220:1::1]', $stream->getHost());
    }
}
