<?php

namespace App\Alerting\Transport;

use App\Monitoring\Net\NetworkPolicy;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\TransferStats;
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

    /**
     * @param  \Closure|null  $handler  Guzzle handler; defaults to cURL. A seam for tests.
     */
    public function __construct(private readonly AddressResolver $resolver, private readonly ?\Closure $handler = null) {}

    /**
     * @param  array<string, mixed>  $options  json, form_params, body and headers.
     *
     * @throws DeliveryFailure
     */
    public function send(string $method, string $url, array $options = []): OutboundResponse
    {
        [$scheme, $host, $port, $url] = self::parseTarget($url);

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

        $isLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $curl = [];
        if (! $isLiteral) {
            $curl[CURLOPT_RESOLVE] = ["{$host}:{$port}:".implode(',', $addresses)];
        }

        $cap = config()->integer('alerting.send.max_response_bytes', 65536);
        $sink = new CappedStream(Utils::streamFor(fopen('php://temp', 'r+b')), $cap);

        // Callers may only shape the request; every guard option is set here and cannot be overridden.
        $options = array_intersect_key($options, array_flip(['json', 'form_params', 'body', 'headers']));
        $headers = is_array($options['headers'] ?? null) ? $options['headers'] : [];
        $hasUserAgent = false;
        foreach (array_keys($headers) as $name) {
            $hasUserAgent = $hasUserAgent || strtolower((string) $name) === 'user-agent';
        }
        if (! $hasUserAgent) {
            $headers['User-Agent'] = 'IntraWatch/'.config()->string('app.version', 'dev');
        }
        $options['headers'] = $headers;

        $captured = null;
        $primaryIp = null;
        $options = [
            ...$options,
            'allow_redirects' => false,
            'proxy' => '',
            'connect_timeout' => config()->integer('alerting.send.connect_timeout_seconds', 5),
            'timeout' => config()->integer('alerting.send.timeout_seconds', 10),
            'http_errors' => false,
            'verify' => true,
            'sink' => $sink,
            'curl' => $curl,
            'on_headers' => function (ResponseInterface $r) use (&$captured): void {
                $captured = $r;
            },
            'on_stats' => function (TransferStats $stats) use (&$primaryIp): void {
                $ip = $stats->getHandlerStat('primary_ip');
                $primaryIp = is_string($ip) && $ip !== '' ? $ip : null;
            },
        ];

        $client = new Client(['handler' => HandlerStack::create($this->handler ?? new CurlHandler)]);

        try {
            $response = $client->request($method, $url, $options);
        } catch (GuzzleException $e) {
            // An oversized body aborts the transfer; the status and the first bytes are still usable.
            if (! $sink->overflowed() || ! $captured instanceof ResponseInterface) {
                throw self::transportFailure($e);
            }
            $response = $captured;
        }

        // The connection must have gone to a vetted address, whatever the client resolved on its own.
        if ($primaryIp !== null && ! self::isVetted($primaryIp, $addresses)) {
            throw new DeliveryFailure('Blocked target', permanent: true);
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

    /**
     * Accepts an IP literal or a strict ASCII host name, so that the client's own URL parser reads the same host
     * that is resolved and pinned here.
     *
     * @return array{0: string, 1: string, 2: int, 3: string} Scheme, host, port and the URL to request.
     *
     * @throws DeliveryFailure
     */
    private static function parseTarget(string $url): array
    {
        $invalid = new DeliveryFailure('Invalid target URL', permanent: true);
        $parts = parse_url($url);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            throw $invalid;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            throw $invalid;
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw $invalid;
            }
            $authority = "[{$host}]";
        } else {
            if (preg_match('/[^\x00-\x7F]/', $host) === 1) {
                $ascii = function_exists('idn_to_ascii')
                    ? idn_to_ascii($host, IDNA_DEFAULT | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46)
                    : false;
                if ($ascii === false || $ascii === '') {
                    throw $invalid;
                }
                $host = $ascii;
            }
            $host = strtolower($host);
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false && ! self::isStrictName($host)) {
                throw $invalid;
            }
            $authority = $host;
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $target = $scheme.'://'.$authority.(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '')
            .(isset($parts['query']) ? '?'.$parts['query'] : '');

        return [$scheme, $host, $port, $target];
    }

    private static function isStrictName(string $host): bool
    {
        $label = '[a-z0-9]([a-z0-9-]*[a-z0-9])?';
        if (preg_match("/^{$label}(\\.{$label})*\\.?\$/i", $host) !== 1 || strlen($host) > 253) {
            return false;
        }

        foreach (explode('.', rtrim($host, '.')) as $part) {
            // All-numeric and hex-numeric labels are read as IPv4 numbers by the client.
            if (ctype_digit($part) || preg_match('/^0x[0-9a-f]*$/i', $part) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $vetted
     */
    private static function isVetted(string $ip, array $vetted): bool
    {
        $packed = @inet_pton(trim($ip, '[]'));
        foreach ($vetted as $address) {
            if ($packed !== false && @inet_pton(trim($address, '[]')) === $packed) {
                return true;
            }
        }

        return false;
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
