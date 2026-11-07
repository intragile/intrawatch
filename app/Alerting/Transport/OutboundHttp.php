<?php

namespace App\Alerting\Transport;

use App\Monitoring\Net\NetworkPolicy;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

/**
 * Outbound HTTP for notification senders. Resolves the host once, refuses blocked addresses, pins the
 * connection to the vetted addresses so a second lookup cannot rebind, and never follows redirects.
 * Blocking; for queue workers only.
 */
final class OutboundHttp
{
    private const MAX_RETRY_AFTER = 300;

    private const TLS_ERRNOS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    public function __construct(private readonly AddressResolver $resolver) {}

    /**
     * @param  array<string, mixed>  $options  json, form_params, body and headers.
     *
     * @throws DeliveryFailure
     */
    public function send(string $method, string $url, array $options = []): OutboundResponse
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? trim((string) ($parts['host'] ?? ''), '[]') : '';
        if ($host === '' || ! in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new DeliveryFailure('Invalid target URL', permanent: true);
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            throw new DeliveryFailure('DNS resolution failed');
        }

        $policy = NetworkPolicy::fromConfig();
        foreach ($addresses as $address) {
            if (! $policy->allows($address)) {
                throw new DeliveryFailure('Blocked target', permanent: true);
            }
        }

        $isLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $curl = [];
        if (! $isLiteral) {
            $curl[CURLOPT_RESOLVE] = ["{$host}:{$port}:".implode(',', $addresses)];
        }

        $cap = config()->integer('alerting.send.max_response_bytes', 65536);
        $sink = new CappedStream(Utils::streamFor(fopen('php://temp', 'r+b')), $cap);

        $headers = is_array($options['headers'] ?? null) ? $options['headers'] : [];
        $options['headers'] = ['User-Agent' => 'IntraWatch/'.config()->string('app.version', 'dev')] + $headers;

        $options += [
            'allow_redirects' => false,
            'proxy' => '',
            'connect_timeout' => config()->integer('alerting.send.connect_timeout_seconds', 5),
            'timeout' => config()->integer('alerting.send.timeout_seconds', 10),
            'http_errors' => false,
            'verify' => true,
            'sink' => $sink,
            'curl' => $curl,
        ];

        $client = new Client(['handler' => HandlerStack::create(new CurlHandler)]);

        try {
            $response = $client->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw self::transportFailure($e);
        }

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return new OutboundResponse($status, $response->getHeaders(), $sink->contents());
        }

        $retryable = $status === 408 || $status === 429 || $status >= 500;
        throw new DeliveryFailure(
            "HTTP {$status}",
            permanent: ! $retryable,
            retryAfter: $retryable ? self::retryAfter($response) : null,
        );
    }

    private static function retryAfter(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));

        return ctype_digit($value) ? min((int) $value, self::MAX_RETRY_AFTER) : null;
    }

    private static function transportFailure(GuzzleException $e): DeliveryFailure
    {
        $context = method_exists($e, 'getHandlerContext') ? $e->getHandlerContext() : [];
        $errno = is_array($context) && is_int($context['errno'] ?? null) ? $context['errno'] : 0;

        return match (true) {
            $errno === 28 => new DeliveryFailure('Timed out'),
            in_array($errno, self::TLS_ERRNOS, true) => new DeliveryFailure('TLS error'),
            default => new DeliveryFailure('Connection failed'),
        };
    }
}
