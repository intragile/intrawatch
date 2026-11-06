<?php

namespace App\Monitoring\Domain;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Interceptor\SetRequestHeaderIfUnset;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\Client\TimeoutException;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\ConnectException;
use App\Monitoring\Net\BlockedTarget;
use App\Monitoring\Net\GuardedConnector;
use Carbon\CarbonImmutable;
use League\Uri\BaseUri;
use League\Uri\Http as HttpUri;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * Looks up a domain over RDAP (RFC 9083) through the guarded connector, following the redirects RDAP servers
 * use to point at the server that holds the data.
 */
final class RdapClient
{
    /** Bytes of a response that are read; a larger response is rejected as invalid. */
    public const BODY_LIMIT = 1048576;

    private const MAX_REDIRECTS = 5;

    private const MAX_REGISTRAR_LENGTH = 255;

    private readonly HttpClient $http;

    public function __construct(GuardedConnector $connector, ?string $caFile = null)
    {
        $tls = new ClientTlsContext('');
        if ($caFile !== null) {
            $tls = $tls->withCaFile($caFile);
        }

        $this->http = (new HttpClientBuilder)
            ->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory($connector, (new ConnectContext)->withTlsContext($tls))))
            ->retry(0)
            // Redirects are followed by lookup(), which refuses to leave https.
            ->followRedirects(0)
            ->skipDefaultUserAgent()
            ->intercept(new SetRequestHeaderIfUnset('user-agent', 'IntraWatch/'.config()->string('app.version', '1')))
            ->build();
    }

    /**
     * @param  string  $domain  The registrable domain in ASCII form.
     * @param  string  $base  The RDAP base URL of the TLD, ending in a slash.
     *
     * @throws CancelledException
     */
    public function lookup(string $domain, string $base, float $timeout, Cancellation $cancellation, CarbonImmutable $now): DomainExpiry
    {
        $uri = HttpUri::new($base.'domain/'.rawurlencode($domain));

        try {
            for ($redirects = 0; ; $redirects++) {
                $response = $this->http->request($this->request($uri, $timeout), $cancellation);
                $status = $response->getStatus();
                $location = $response->getHeader('location');
                if (! in_array($status, [301, 302, 303, 307, 308], true) || $location === null) {
                    break;
                }

                $response->getBody()->close();
                if ($redirects >= self::MAX_REDIRECTS) {
                    return DomainExpiry::error($domain, DomainExpiry::SOURCE_RDAP, 'Too many redirects', $now);
                }

                try {
                    $next = HttpUri::new((string) BaseUri::from($uri)->resolve($location));
                } catch (Throwable) {
                    return DomainExpiry::error($domain, DomainExpiry::SOURCE_RDAP, 'Invalid redirect', $now);
                }
                $scheme = strtolower($next->getScheme());
                if (! in_array($scheme, ['http', 'https'], true) || (strtolower($uri->getScheme()) === 'https' && $scheme !== 'https')) {
                    return DomainExpiry::error($domain, DomainExpiry::SOURCE_RDAP, 'Insecure redirect', $now);
                }
                $uri = $next;
            }

            if ($status === 404 && $this->sameOrigin($uri, HttpUri::new($base))) {
                return new DomainExpiry($domain, DomainExpiry::SOURCE_RDAP, DomainExpiry::STATUS_NOT_FOUND, null, null, null, $now);
            }
            if ($status !== 200) {
                return DomainExpiry::error($domain, DomainExpiry::SOURCE_RDAP, "HTTP {$status}", $now);
            }

            $body = $this->readBody($response, $cancellation);
        } catch (HttpException $e) {
            return DomainExpiry::error($domain, DomainExpiry::SOURCE_RDAP, $this->describe($e, $timeout), $now);
        }

        $document = $body === null ? null : json_decode($body, true);
        if (! is_array($document) || (isset($document['events']) && ! is_array($document['events']))) {
            return DomainExpiry::error($domain, DomainExpiry::SOURCE_RDAP, 'Invalid RDAP response', $now);
        }

        $expiresAt = $this->expiration($document);

        return new DomainExpiry(
            $domain,
            DomainExpiry::SOURCE_RDAP,
            $expiresAt === null ? DomainExpiry::STATUS_UNKNOWN : DomainExpiry::STATUS_OK,
            $expiresAt,
            $this->registrar($document),
            null,
            $now,
        );
    }

    private function request(UriInterface $uri, float $timeout): Request
    {
        $request = new Request($uri);
        $request->setHeader('accept', 'application/rdap+json, application/json');
        $request->setTcpConnectTimeout($timeout);
        $request->setTlsHandshakeTimeout($timeout);
        $request->setTransferTimeout($timeout);
        $request->setInactivityTimeout($timeout);
        $request->setBodySizeLimit(PHP_INT_MAX);

        return $request;
    }

    /**
     * The body, or null when it exceeds the limit.
     */
    private function readBody(Response $response, Cancellation $cancellation): ?string
    {
        $stream = $response->getBody();
        $body = '';

        while (($chunk = $stream->read($cancellation)) !== null) {
            $body .= $chunk;
            if (strlen($body) > self::BODY_LIMIT) {
                $stream->close();

                return null;
            }
        }

        return $body;
    }

    /**
     * @param  array<mixed>  $document
     */
    private function expiration(array $document): ?CarbonImmutable
    {
        foreach ((array) ($document['events'] ?? []) as $event) {
            if (! is_array($event) || ! is_string($event['eventAction'] ?? null) || strtolower($event['eventAction']) !== 'expiration') {
                continue;
            }

            $date = $event['eventDate'] ?? null;
            if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?)?$/i', $date) !== 1) {
                return null;
            }

            try {
                return CarbonImmutable::parse($date, 'UTC')->utc();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * The formatted name of the entity with the registrar role.
     *
     * @param  array<mixed>  $document
     */
    private function registrar(array $document): ?string
    {
        foreach ((array) ($document['entities'] ?? []) as $entity) {
            if (! is_array($entity) || ! in_array('registrar', (array) ($entity['roles'] ?? []), true)) {
                continue;
            }

            $vcard = $entity['vcardArray'] ?? null;
            $card = is_array($vcard) ? ($vcard[1] ?? null) : null;
            foreach (is_array($card) ? $card : [] as $property) {
                if (is_array($property) && ($property[0] ?? null) === 'fn' && is_string($property[3] ?? null) && trim($property[3]) !== '') {
                    return mb_substr(mb_scrub(trim($property[3]), 'UTF-8'), 0, self::MAX_REGISTRAR_LENGTH);
                }
            }
        }

        return null;
    }

    /**
     * Whether a response came from the bootstrap server itself rather than from a server it redirected to; only
     * the registry's own server can state that a domain does not exist.
     */
    private function sameOrigin(UriInterface $final, UriInterface $base): bool
    {
        $port = static fn (UriInterface $uri): ?int => $uri->getPort() ?? match (strtolower($uri->getScheme())) {
            'https' => 443,
            'http' => 80,
            default => null,
        };

        return strtolower($final->getHost()) === strtolower($base->getHost()) && $port($final) === $port($base);
    }

    private function describe(HttpException $e, float $timeout): string
    {
        if ($e instanceof TimeoutException) {
            return 'Timed out after '.self::seconds($timeout).' s';
        }

        for ($previous = $e->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
            if ($previous instanceof BlockedTarget) {
                return 'Blocked target';
            }
            if ($previous instanceof ConnectException) {
                return 'Connection failed';
            }
        }

        return 'Request failed';
    }

    public static function seconds(float $seconds): string
    {
        return $seconds == floor($seconds) ? (string) (int) $seconds : rtrim(rtrim(number_format($seconds, 3, '.', ''), '0'), '.');
    }
}
