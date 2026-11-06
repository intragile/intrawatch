<?php

namespace App\Monitoring\Checks\Http;

use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Interceptor\FollowRedirects;
use Amp\Http\Client\Interceptor\SetRequestHeaderIfUnset;
use Amp\Http\Client\Request;
use Amp\Http\Tunnel\Http1TunnelConnector;
use Amp\Socket\Certificate;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\SocketConnector;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Net\GuardedConnector;
use App\Monitoring\Net\ProxyConnector;
use App\Monitoring\Net\Socks5TunnelConnector;
use League\Uri\Http as HttpUri;
use RuntimeException;
use Throwable;

/**
 * Builds a fresh HTTP client and request for one check of an http monitor.
 *
 * Every connection, including each redirect hop and the hop to a proxy, is opened through the guarded
 * connector, so the network policy and the resolve-then-connect protection apply to all of them. A new pool is
 * created per check and requests ask for the connection to be closed, so no connection, and no credential, is
 * ever shared between checks or monitors.
 *
 * Credentials and secret headers are set on the request itself rather than through an interceptor, so the
 * client drops them when a redirect leaves the original origin.
 */
final class ClientFactory
{
    public function __construct(
        private readonly GuardedConnector $connector,
        private readonly ?string $caFile = null,
    ) {}

    public function create(MonitorSpec $spec): CheckClient
    {
        $config = $spec->config;
        $secrets = $spec->secrets;
        $timeout = (float) $spec->timeoutSeconds;
        $temporaryFiles = [];

        try {
            $tls = new ClientTlsContext('');
            if ($this->caFile !== null) {
                $tls = $tls->withCaFile($this->caFile);
            }
            if (($config['tls_verify'] ?? true) === false) {
                $tls = $tls->withoutPeerVerification();
            }
            if (($config['mtls'] ?? false) === true) {
                $temporaryFiles[] = $keyFile = $this->writeKeyMaterial($secrets);
                $tls = $tls->withCertificate(new Certificate($keyFile));
            }

            $context = (new ConnectContext)->withTlsContext($tls)->withConnectTimeout($timeout);
            $tracker = new ConnectionTracker(new DefaultConnectionFactory($this->connectorFor($config, $secrets), $context));

            $redirects = is_int($config['max_redirects'] ?? null) ? $config['max_redirects'] : 0;
            $builder = (new HttpClientBuilder)
                ->usingPool(new UnlimitedConnectionPool($tracker))
                ->interceptNetwork($tracker)
                ->retry(0)
                // Redirects are followed by an interceptor configured below, without automatic Referer headers.
                ->followRedirects(0)
                ->skipDefaultUserAgent()
                // Without decompression the body cap also bounds memory; a compressed body could inflate far beyond it.
                ->skipAutomaticCompression();

            // A Referer would carry the original URL, query string included, to the next hop, even across origins.
            if ($redirects > 0) {
                $builder = $builder->intercept(new FollowRedirects($redirects, autoReferrer: false));
            }
            $http = $builder
                ->intercept(new SetRequestHeaderIfUnset('user-agent', 'IntraWatch/'.config()->string('app.version', '1')))
                ->build();

            return new CheckClient($http, $this->request($config, $secrets, $timeout), $tracker, $temporaryFiles);
        } catch (Throwable $e) {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $secrets
     */
    private function request(array $config, array $secrets, float $timeout): Request
    {
        $url = is_string($config['url'] ?? null) ? $config['url'] : '';
        $method = $config['method'] ?? null;
        $method = is_string($method) && $method !== '' ? $method : 'GET';

        // Credentials belong in the auth settings; userinfo in the URL is never sent.
        $request = new Request(HttpUri::new($url)->withUserInfo(''), $method);
        $request->setProtocolVersions(['1.1', '1.0']);
        $request->setTcpConnectTimeout($timeout);
        $request->setTlsHandshakeTimeout($timeout);
        $request->setTransferTimeout($timeout);
        $request->setInactivityTimeout($timeout);
        // The checker stops reading after its own cap; the client limit only has to stay out of the way.
        $request->setBodySizeLimit(PHP_INT_MAX);

        $body = $config['body'] ?? null;
        if (is_string($body) && $body !== '' && ! in_array($method, ['GET', 'HEAD'], true)) {
            $request->setBody($body);
        }

        foreach ([$config['headers'] ?? [], $secrets['secret_headers'] ?? []] as $headers) {
            if (! is_array($headers)) {
                continue;
            }
            foreach ($headers as $name => $value) {
                if (is_string($name) && $name !== '' && is_string($value)) {
                    $request->setHeader($name, $value);
                }
            }
        }

        $auth = is_array($config['auth'] ?? null) ? $config['auth'] : [];
        $type = $auth['type'] ?? 'none';
        if ($type === 'basic') {
            $username = is_string($auth['username'] ?? null) ? $auth['username'] : '';
            $password = is_string($secrets['basic_password'] ?? null) ? $secrets['basic_password'] : '';
            $request->setHeader('authorization', 'Basic '.base64_encode($username.':'.$password));
        } elseif ($type === 'bearer' && is_string($secrets['bearer_token'] ?? null)) {
            $request->setHeader('authorization', 'Bearer '.$secrets['bearer_token']);
        }

        $request->setHeader('connection', 'close');

        return $request;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $secrets
     */
    private function connectorFor(array $config, array $secrets): SocketConnector
    {
        $proxy = $config['proxy'] ?? null;
        if ($proxy === null) {
            return $this->connector;
        }

        // A proxy that is configured but unusable fails every connection instead of connecting directly.
        $parts = is_array($proxy) && is_string($proxy['url'] ?? null) ? parse_url($proxy['url']) : false;
        if (! is_array($proxy) || ! is_array($parts) || ! isset($parts['host'], $parts['port']) || ! in_array($parts['scheme'] ?? '', ['http', 'socks5'], true)) {
            return new ProxyConnector(null);
        }

        $host = str_contains($parts['host'], ':') && ! str_starts_with($parts['host'], '[') ? "[{$parts['host']}]" : $parts['host'];
        $address = "{$host}:{$parts['port']}";
        $username = is_string($proxy['username'] ?? null) && $proxy['username'] !== '' ? $proxy['username'] : null;
        $password = is_string($secrets['proxy_password'] ?? null) ? $secrets['proxy_password'] : '';

        if ($parts['scheme'] === 'socks5') {
            return new ProxyConnector(new Socks5TunnelConnector($address, $this->connector, $username, $password));
        }

        $headers = $username === null ? [] : ['Proxy-Authorization' => 'Basic '.base64_encode($username.':'.$password)];

        return new ProxyConnector(new Http1TunnelConnector($address, $headers, $this->connector));
    }

    /**
     * Writes the client certificate and key to a private temporary file, which CheckClient deletes.
     *
     * @param  array<string, mixed>  $secrets
     */
    private function writeKeyMaterial(array $secrets): string
    {
        $cert = is_string($secrets['mtls_cert'] ?? null) ? $secrets['mtls_cert'] : '';
        $key = is_string($secrets['mtls_key'] ?? null) ? $secrets['mtls_key'] : '';

        $path = tempnam(sys_get_temp_dir(), 'intrawatch-mtls-');
        if ($path === false) {
            throw new RuntimeException('Cannot create a temporary file for the client certificate.');
        }

        chmod($path, 0600);
        if (file_put_contents($path, rtrim($cert)."\n".rtrim($key)."\n") === false) {
            @unlink($path);

            throw new RuntimeException('Cannot write the client certificate.');
        }

        return $path;
    }
}
