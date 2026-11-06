<?php

namespace App\Monitoring\Checks;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Interceptor\TooManyRedirectsException;
use Amp\Http\Client\ParseException;
use Amp\Http\Client\Response;
use Amp\Http\Client\SocketException;
use Amp\Http\Client\TimeoutException;
use Amp\Http\Client\TlsException;
use Amp\Socket\ConnectException;
use Amp\TimeoutCancellation;
use App\Monitoring\Checks\Http\CheckClient;
use App\Monitoring\Checks\Http\ClientFactory;
use App\Monitoring\Checks\Http\DomainFactsProvider;
use App\Monitoring\Checks\Http\ResponseAssertions;
use App\Monitoring\Checks\Http\StatusRanges;
use App\Monitoring\Checks\Http\TlsFactsProvider;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Net\BlockedTarget;
use App\Monitoring\Net\ProxyFailure;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * Requests a URL and checks the status code, body and headers of the response.
 *
 * The whole check, from DNS and connect through TLS, headers and body, ends at the monitor's timeout. Failure
 * messages never contain the URL, credentials, header values or the response body.
 */
final class HttpChecker implements Checker
{
    /** Bytes of the body that are read; keyword and JSON assertions see only this prefix. */
    public const BODY_LIMIT = 1048576;

    private const ECONNREFUSED = [111, 61];

    public function __construct(
        private readonly ClientFactory $clients,
        private readonly ?TlsFactsProvider $tlsFacts = null,
        private readonly ?DomainFactsProvider $domainFacts = null,
    ) {}

    public function check(MonitorSpec $spec, Cancellation $cancellation): CheckOutcome
    {
        $timeout = $spec->timeoutSeconds;
        $deadline = new CompositeCancellation($cancellation, new TimeoutCancellation($timeout));
        $started = hrtime(true);
        $client = null;

        try {
            $client = $this->clients->create($spec);
            $response = $client->http->request($client->request, $deadline);
            $body = $this->readBody($response, $deadline);
            $latencyMs = (int) round((hrtime(true) - $started) / 1_000_000);

            return $this->evaluate($spec, $client, $response, $body, $latencyMs, $deadline);
        } catch (CancelledException) {
            $cancellation->throwIfRequested();

            return CheckOutcome::down("Timed out after {$timeout} s");
        } catch (HttpException $e) {
            return CheckOutcome::down($this->describe($e, $timeout));
        } catch (Throwable $e) {
            return CheckOutcome::fromThrowable($e);
        } finally {
            $client?->dispose();
        }
    }

    private function readBody(Response $response, Cancellation $cancellation): string
    {
        $stream = $response->getBody();
        $body = '';

        while (strlen($body) < self::BODY_LIMIT && ($chunk = $stream->read($cancellation)) !== null) {
            $body .= substr($chunk, 0, self::BODY_LIMIT - strlen($body));
        }

        return $body;
    }

    private function evaluate(MonitorSpec $spec, CheckClient $client, Response $response, string $body, int $latencyMs, Cancellation $cancellation): CheckOutcome
    {
        $config = $spec->config;
        $status = $response->getStatus();
        $uri = $response->getRequest()->getUri();

        $facts = ['http.status' => $status, 'http.final_url' => $this->publicUrl($uri)];
        $watch = [];
        $degrade = $this->addProviderFacts($spec, $client, $uri, $facts, $watch, $cancellation);

        $accepted = StatusRanges::parse(is_array($config['accepted_status'] ?? null) ? $config['accepted_status'] : []);
        $failure = $accepted->accepts($status)
            ? ResponseAssertions::failure($config, $body, $response->getHeaders())
            : "HTTP {$status}";

        return match (true) {
            $failure !== null => CheckOutcome::down($failure, $latencyMs, $facts, $watch),
            $degrade !== null => CheckOutcome::degraded($degrade, $latencyMs, $facts, $watch),
            default => CheckOutcome::up($latencyMs, '', $facts, $watch),
        };
    }

    /**
     * Merges the facts of the TLS and domain providers and returns the TLS provider's degrade reason.
     *
     * @param  array<string, scalar|list<scalar>|null>  $facts
     * @param  array<string, string>  $watch
     */
    private function addProviderFacts(MonitorSpec $spec, CheckClient $client, UriInterface $uri, array &$facts, array &$watch, Cancellation $cancellation): ?string
    {
        $host = trim($uri->getHost(), '[]');
        $tls = $client->tlsInfo();
        $degrade = null;

        try {
            if ($this->tlsFacts !== null && $tls !== null && $uri->getScheme() === 'https' && ClientFactory::capturesCertificates($spec)) {
                $extra = $this->tlsFacts->inspect($spec, $host, $tls, $cancellation);
                $facts += $extra['facts'];
                $watch += $extra['watch'];
                $degrade = $extra['degrade'];
            }

            if ($this->domainFacts !== null && ($spec->config['domain_expiry'] ?? true) === true) {
                $extra = $this->domainFacts->inspect($spec, $host, $cancellation);
                $facts += $extra['facts'];
                $watch += $extra['watch'];
            }
        } catch (CancelledException $e) {
            throw $e;
        } catch (Throwable) {
            // Supplementary facts never decide the outcome of the request itself.
        }

        return $degrade;
    }

    /**
     * The URL without userinfo, query string and fragment.
     */
    private function publicUrl(UriInterface $uri): string
    {
        return (string) $uri->withUserInfo('')->withQuery('')->withFragment('');
    }

    private function describe(HttpException $e, int $timeout): string
    {
        if ($e instanceof TooManyRedirectsException) {
            return 'Too many redirects';
        }
        if ($e instanceof TimeoutException) {
            return "Timed out after {$timeout} s";
        }
        if ($e instanceof TlsException) {
            return 'TLS handshake failed: '.$this->tlsReason($e);
        }
        if ($e instanceof ParseException) {
            return 'Invalid HTTP response';
        }

        for ($previous = $e->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
            if ($previous instanceof BlockedTarget) {
                return 'Blocked target';
            }
            if ($previous instanceof ProxyFailure) {
                return 'Proxy connection failed';
            }
            if ($previous instanceof ConnectException) {
                return $this->connectFailure($previous, $timeout);
            }
        }

        if ($e instanceof SocketException && str_contains($e->getMessage(), 'closed')) {
            return 'Connection closed before the response was complete';
        }

        return 'Request failed';
    }

    private function connectFailure(ConnectException $e, int $timeout): string
    {
        $message = $e->getMessage();

        return match (true) {
            in_array($e->getCode(), self::ECONNREFUSED, true) => 'Connection refused',
            $e->getCode() === 110 || str_contains($message, 'timed out') => "Timed out after {$timeout} s",
            $e->getPrevious() !== null && str_contains($message, 'DNS') => 'Connection failed: DNS resolution failed',
            default => 'Connection failed',
        };
    }

    /**
     * A short, fixed-vocabulary reason: OpenSSL reason strings are kept, anything that could carry a host
     * name or address is replaced.
     */
    private function tlsReason(TlsException $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'did not match expected')) {
            return 'certificate name mismatch';
        }

        $reason = strtolower(trim((string) substr((string) strrchr($message, ':'), 1)));

        return preg_match('/^[a-z0-9 ()_-]{3,80}$/', $reason) === 1 ? $reason : 'handshake error';
    }
}
