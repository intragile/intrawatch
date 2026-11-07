<?php

namespace Tests\Feature\Alerting\Senders;

use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Channels\Senders\EmailSender;
use App\Alerting\Channels\Senders\SmtpTransportFactory;
use App\Alerting\Messages\OutgoingNotification;
use App\Alerting\Transport\AddressResolver;
use App\Alerting\Transport\DeliveryFailure;
use App\Enums\ChannelType;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

class EmailSenderTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $built = [];

    /** @var list<RawMessage> */
    private array $sent = [];

    private function sender(?TransportException $failWith = null, string $resolved = '93.184.216.34'): EmailSender
    {
        $this->built = [];
        $this->sent = [];
        $resolver = new class($resolved) implements AddressResolver
        {
            public function __construct(private string $ip) {}

            public function resolve(string $host): array
            {
                return $this->ip === '' ? [] : [$this->ip];
            }
        };
        $test = $this;
        $factory = new class($test, $failWith) extends SmtpTransportFactory
        {
            public function __construct(private EmailSenderTest $test, private ?TransportException $fail) {}

            public function make(string $connectAddress, string $hostName, int $port, string $encryption, ?string $username, ?string $password): TransportInterface
            {
                $this->test->record(compact('connectAddress', 'hostName', 'port', 'encryption', 'username', 'password'));

                return new class($this->test, $this->fail) implements TransportInterface
                {
                    public function __construct(private EmailSenderTest $test, private ?TransportException $fail) {}

                    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
                    {
                        if ($this->fail !== null) {
                            throw $this->fail;
                        }
                        $this->test->capture($message);

                        return null;
                    }

                    public function __toString(): string
                    {
                        return 'fake';
                    }
                };
            }
        };

        return new EmailSender($resolver, $factory);
    }

    /** @param array<string, mixed> $args */
    public function record(array $args): void
    {
        $this->built[] = $args;
    }

    public function capture(RawMessage $message): void
    {
        $this->sent[] = $message;
    }

    private function notification(): OutgoingNotification
    {
        return new OutgoingNotification("[DOWN] Web\r\nBcc: x@evil.test", "Line <b>1</b>\nLine 2 & more", 'critical', [], false);
    }

    public function test_email_uses_channel_smtp_or_app_mailer(): void
    {
        $smtp = ['host' => 'smtp.example.test', 'port' => 2525, 'encryption' => 'tls', 'username' => 'u', 'from_address' => 'alerts@example.test', 'from_name' => 'IntraWatch'];
        $spec = new ChannelSpec(1, 1, ChannelType::Email, ['to' => ['a@example.test', 'b@example.test'], 'smtp' => $smtp], ['smtp_password' => 'pw-secret']);

        $this->sender()->send($spec, $this->notification(), 'iw-5');
        $this->assertSame(
            ['connectAddress' => '93.184.216.34', 'hostName' => 'smtp.example.test', 'port' => 2525, 'encryption' => 'tls', 'username' => 'u', 'password' => 'pw-secret'],
            $this->built[0],
        );
        $email = $this->sent[0];
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame('[DOWN] Web Bcc: x@evil.test', $email->getSubject());
        $this->assertSame(['a@example.test', 'b@example.test'], array_map(fn ($a) => $a->getAddress(), $email->getTo()));
        $this->assertSame('alerts@example.test', $email->getFrom()[0]->getAddress());
        $this->assertSame('IntraWatch', $email->getFrom()[0]->getName());
        $this->assertSame("Line <b>1</b>\nLine 2 & more", $email->getTextBody());
        $this->assertSame("Line &lt;b&gt;1&lt;/b&gt;<br>\nLine 2 &amp; more", $email->getHtmlBody());
        $this->assertSame('iw-5', $email->getHeaders()->getHeaderBody('X-IntraWatch-Delivery'));
        $this->assertNull($email->getBcc() ?: null);

        // Port and encryption fall back to the documented defaults.
        $minimal = new ChannelSpec(1, 1, ChannelType::Email, ['to' => ['a@example.test'], 'smtp' => ['host' => 'smtp.example.test', 'port' => null, 'from_address' => 'alerts@example.test']], []);
        $this->sender()->send($minimal, $this->notification(), 'iw-6');
        $this->assertSame(587, $this->built[0]['port']);
        $this->assertSame('starttls', $this->built[0]['encryption']);
        $this->assertNull($this->built[0]['password']);

        // Without smtp the application mailer delivers.
        config(['mail.default' => 'array', 'mail.from.address' => 'app@example.test']);
        $app = new ChannelSpec(1, 1, ChannelType::Email, ['to' => ['a@example.test'], 'smtp' => null], []);
        $this->sender()->send($app, $this->notification(), 'iw-7');
        $this->assertSame([], $this->built);
        $transport = Mail::mailer('array')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $messages = $transport->messages()->all();
        $this->assertCount(1, $messages);
        $sentMessage = $messages[0];
        $this->assertInstanceOf(SentMessage::class, $sentMessage);
        $original = $sentMessage->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $original);
        $this->assertSame('a@example.test', $original->getTo()[0]->getAddress());
        $this->assertStringContainsString('Line 2 &amp; more', (string) $original->getHtmlBody());
    }

    public function test_smtp_errors_are_classified_and_carry_only_the_code(): void
    {
        $spec = new ChannelSpec(1, 1, ChannelType::Email, ['to' => ['a@example.test'], 'smtp' => ['host' => 'smtp.example.test', 'from_address' => 'alerts@example.test']], ['smtp_password' => 'pw-secret']);

        foreach ([[451, false, 'SMTP error: code 451'], [550, true, 'SMTP error: code 550'], [535, true, 'SMTP error: code 535'], [0, false, 'SMTP error']] as [$code, $permanent, $message]) {
            try {
                $this->sender(new TransportException('Expected response code "250" but got "'.$code.'" with message "pw-secret leaked"', $code))->send($spec, $this->notification(), 'iw-1');
                $this->fail('Expected DeliveryFailure');
            } catch (DeliveryFailure $e) {
                $this->assertSame($message, $e->getMessage());
                $this->assertSame($permanent, $e->permanent, (string) $code);
            }
        }
    }

    public function test_blocked_or_unresolvable_smtp_hosts_are_refused_before_connecting(): void
    {
        config(['monitoring.worker.blocked_networks' => ['10.0.0.0/8']]);
        $spec = new ChannelSpec(1, 1, ChannelType::Email, ['to' => ['a@example.test'], 'smtp' => ['host' => 'smtp.internal.test', 'from_address' => 'alerts@example.test']], []);

        try {
            $sender = $this->sender(resolved: '10.1.2.3');
            $sender->send($spec, $this->notification(), 'iw-1');
            $this->fail('Expected DeliveryFailure');
        } catch (DeliveryFailure $e) {
            $this->assertSame('Blocked target', $e->getMessage());
            $this->assertTrue($e->permanent);
            $this->assertSame([], $this->built);
        }

        try {
            $this->sender(resolved: '')->send($spec, $this->notification(), 'iw-1');
            $this->fail('Expected DeliveryFailure');
        } catch (DeliveryFailure $e) {
            $this->assertSame('DNS resolution failed', $e->getMessage());
            $this->assertFalse($e->permanent);
        }
    }
}
