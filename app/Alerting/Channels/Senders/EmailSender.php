<?php

namespace App\Alerting\Channels\Senders;

use App\Alerting\Channels\ChannelSender;
use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Channels\Definitions\EmailDefinition;
use App\Alerting\Messages\OutgoingNotification;
use App\Alerting\Transport\AddressResolver;
use App\Alerting\Transport\DeliveryFailure;
use App\Monitoring\Net\NetworkPolicy;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\ExceptionInterface as MimeException;

final class EmailSender implements ChannelSender
{
    public function __construct(
        private readonly AddressResolver $resolver,
        private readonly SmtpTransportFactory $transports,
    ) {}

    public function send(ChannelSpec $channel, OutgoingNotification $n, string $idempotencyKey): void
    {
        $to = array_values(array_filter(
            is_array($channel->config['to'] ?? null) ? $channel->config['to'] : [],
            is_string(...),
        ));
        if ($to === []) {
            throw new DeliveryFailure('No recipients', permanent: true);
        }

        try {
            $smtp = $channel->config['smtp'] ?? null;
            if (is_array($smtp) && is_string($smtp['host'] ?? null) && $smtp['host'] !== '') {
                $this->viaSmtp($channel, $smtp, $to, $n, $idempotencyKey);
            } else {
                $this->viaApplicationMailer($to, $n, $idempotencyKey);
            }
        } catch (TransportExceptionInterface $e) {
            $code = (int) $e->getCode();
            throw new DeliveryFailure(
                'SMTP error',
                permanent: $code >= 500 && $code < 600,
                detail: $code > 0 ? "code {$code}" : null,
            );
        } catch (MimeException) {
            throw new DeliveryFailure('Invalid e-mail address', permanent: true);
        }
    }

    /**
     * @param  array<array-key, mixed>  $smtp
     * @param  list<string>  $to
     */
    private function viaSmtp(ChannelSpec $channel, array $smtp, array $to, OutgoingNotification $n, string $key): void
    {
        $host = is_string($smtp['host'] ?? null) ? trim($smtp['host']) : '';
        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            throw new DeliveryFailure('DNS resolution failed');
        }
        $policy = NetworkPolicy::forNotifications();
        foreach ($addresses as $address) {
            if (! $policy->allows($address)) {
                throw new DeliveryFailure('Blocked target', permanent: true);
            }
        }

        $port = is_int($smtp['port'] ?? null) ? $smtp['port'] : EmailDefinition::SMTP_DEFAULT_PORT;
        $encryption = $smtp['encryption'] ?? null;
        if (! is_string($encryption) || ! in_array($encryption, ['starttls', 'tls', 'none'], true)) {
            $encryption = EmailDefinition::SMTP_DEFAULT_ENCRYPTION;
        }
        $username = is_string($smtp['username'] ?? null) && $smtp['username'] !== '' ? $smtp['username'] : null;

        $transport = $this->transports->make(
            $addresses[0],
            $host,
            $port,
            $encryption,
            $username,
            $channel->secretString('smtp_password'),
        );

        $email = $this->compose(new Email, $to, $n, $key);
        $fromAddress = is_string($smtp['from_address'] ?? null) ? $smtp['from_address'] : '';
        $fromName = is_string($smtp['from_name'] ?? null) ? $smtp['from_name'] : '';
        $email->from(new Address($fromAddress, $fromName));

        $transport->send($email);
    }

    /**
     * @param  list<string>  $to
     */
    private function viaApplicationMailer(array $to, OutgoingNotification $n, string $key): void
    {
        Mail::send([], [], function (Message $message) use ($to, $n, $key): void {
            $this->compose($message->getSymfonyMessage(), $to, $n, $key);
        });
    }

    /**
     * @param  list<string>  $to
     */
    private function compose(Email $email, array $to, OutgoingNotification $n, string $key): Email
    {
        $email->to(...array_map(fn (string $a): Address => new Address($a), $to))
            ->subject(self::singleLine($n->title))
            ->text($n->body)
            ->html(nl2br(e($n->body), false));
        $email->getHeaders()->addTextHeader('X-IntraWatch-Delivery', $key);

        return $email;
    }

    private static function singleLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
