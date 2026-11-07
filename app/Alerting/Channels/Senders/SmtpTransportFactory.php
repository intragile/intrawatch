<?php

namespace App\Alerting\Channels\Senders;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Builds the SMTP transport of an e-mail channel. Connects to a vetted IP address while validating the
 * certificate against the configured host name.
 */
class SmtpTransportFactory
{
    private const TIMEOUT_SECONDS = 10.0;

    /**
     * @param  string  $encryption  starttls (required), tls (implicit) or none.
     */
    public function make(
        string $connectAddress,
        string $hostName,
        int $port,
        string $encryption,
        ?string $username,
        #[\SensitiveParameter] ?string $password,
    ): TransportInterface {
        $transport = new EsmtpTransport(filter_var($connectAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? "[{$connectAddress}]" : $connectAddress, $port, $encryption === 'tls');
        $transport->setAutoTls($encryption === 'starttls');
        $transport->setRequireTls($encryption === 'starttls');

        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout(self::TIMEOUT_SECONDS);
            $stream->setStreamOptions(['ssl' => ['crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT, 'peer_name' => $hostName, 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true]]);
        }

        if ($username !== null && $password !== null) {
            $transport->setUsername($username);
            $transport->setPassword($password);
        }

        return $transport;
    }
}
