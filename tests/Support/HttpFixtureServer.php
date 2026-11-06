<?php

namespace Tests\Support;

use Amp\ByteStream\ReadableIterableStream;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\BindContext;
use Amp\Socket\Certificate;
use Amp\Socket\InternetAddress;
use Amp\Socket\ServerSocket;
use Amp\Socket\ServerTlsContext;
use Amp\Socket\Socket;
use Amp\Socket\SocketException;
use Psr\Log\NullLogger;

use function Amp\async;
use function Amp\delay;
use function Amp\Socket\listen;

/**
 * A scripted HTTP server on 127.0.0.1 for checker tests.
 *
 * Routes on the plain and the TLS listener:
 *  - /status/{code}            empty response with that status
 *  - /body?content=...         returns the content; /json?content=... does the same as application/json
 *  - /echo                     returns the request body, with X-Ver: 2 and X-Method headers
 *  - /redirect/{n}             redirects to /redirect/{n-1}; /redirect/0 is a 200
 *  - /redirect-to?port=...     redirects to http://{blocked host}:{port}/fast
 *  - /drip                     sends one byte per second until the client goes away
 *  - /big                      5 MiB body with NEEDLE at the 4 MiB offset, streamed in chunks
 *  - /basic, /bearer, /header  401 unless the expected credential is sent
 *  - /mtls                     401 unless a client certificate was presented
 *  - /fast                     200 "ok"
 *  - /headers                  the received request headers as a JSON object of lowercase name to values
 *
 * With certificates, the same routes are also served over TLS, once without and once with a mandatory client
 * certificate. A separate raw listener accepts connections and never answers.
 */
final class HttpFixtureServer
{
    public const BASIC_USER = 'bob';

    public const BASIC_PASSWORD = 'pw-123';

    public const BEARER_TOKEN = 'tok-xyz';

    public const SECRET_HEADER = 'X-Api-Key';

    public const SECRET_HEADER_VALUE = 'hdr-secret-789';

    private readonly SocketHttpServer $server;

    private ?SocketHttpServer $tlsServer = null;

    private ?SocketHttpServer $mtlsServer = null;

    private int $mtlsPort = 0;

    private readonly ServerSocket $hanging;

    private readonly DeferredCancellation $stopping;

    /** @var list<Socket> */
    private array $held = [];

    private int $port = 0;

    private int $tlsPort = 0;

    private bool $stopped = false;

    public function __construct(?TestCertificates $certificates = null)
    {
        $this->stopping = new DeferredCancellation;
        $handler = new ClosureRequestHandler($this->handle(...));

        $this->server = self::createServer();
        $this->server->expose('127.0.0.1:0');
        $this->server->start($handler, new DefaultErrorHandler);
        $this->port = self::portOf($this->server);

        if ($certificates !== null) {
            $tls = (new ServerTlsContext)
                ->withDefaultCertificate(new Certificate($certificates->serverFile))
                ->withCaFile($certificates->caFile)
                ->withPeerCapturing();
            $this->tlsServer = self::createServer();
            $this->tlsServer->expose('127.0.0.1:0', (new BindContext)->withTlsContext($tls));
            $this->tlsServer->start($handler, new DefaultErrorHandler);
            $this->tlsPort = self::portOf($this->tlsServer);

            // Peer verification on the server side makes a client certificate mandatory.
            $this->mtlsServer = self::createServer();
            $this->mtlsServer->expose('127.0.0.1:0', (new BindContext)->withTlsContext($tls->withPeerVerification()->withoutPeerNameVerification()));
            $this->mtlsServer->start($handler, new DefaultErrorHandler);
            $this->mtlsPort = self::portOf($this->mtlsServer);
        }

        $this->hanging = listen('127.0.0.1:0');
        $hanging = $this->hanging;
        async(function () use ($hanging): void {
            while ($client = $hanging->accept()) {
                $this->held[] = $client;
            }
        })->ignore();
    }

    public function url(string $path = '/'): string
    {
        return "http://127.0.0.1:{$this->port}{$path}";
    }

    public function port(): int
    {
        return $this->port;
    }

    public function tlsPort(): int
    {
        return $this->tlsPort;
    }

    /**
     * A TLS listener that requires a client certificate issued by the test CA.
     */
    public function mtlsPort(): int
    {
        return $this->mtlsPort;
    }

    public function hangingUrl(): string
    {
        $address = $this->hanging->getAddress();
        assert($address instanceof InternetAddress);

        return "http://127.0.0.1:{$address->getPort()}/";
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;

        $this->stopping->cancel();
        $this->hanging->close();
        foreach ($this->held as $client) {
            $client->close();
        }
        $this->held = [];
        $this->server->stop();
        $this->tlsServer?->stop();
        $this->mtlsServer?->stop();
    }

    private static function createServer(): SocketHttpServer
    {
        return SocketHttpServer::createForDirectAccess(new NullLogger, enableCompression: false, connectionLimitPerIp: 1000);
    }

    private static function portOf(SocketHttpServer $server): int
    {
        $address = $server->getServers()[0]->getAddress();
        assert($address instanceof InternetAddress);

        return $address->getPort();
    }

    private function handle(Request $request): Response
    {
        $path = $request->getUri()->getPath();
        $segments = explode('/', trim($path, '/'));

        return match ($segments[0]) {
            'status' => new Response((int) ($segments[1] ?? 200)),
            'body' => new Response(200, ['content-type' => 'text/plain'], (string) $request->getQueryParameter('content')),
            'json' => new Response(200, ['content-type' => 'application/json'], (string) $request->getQueryParameter('content')),
            'echo' => new Response(200, ['x-ver' => '2', 'x-method' => $request->getMethod()], $request->getBody()->buffer()),
            'redirect' => $this->redirect((int) ($segments[1] ?? 0)),
            'redirect-to' => new Response(302, ['location' => (string) $request->getQueryParameter('target')]),
            'drip' => new Response(200, ['content-type' => 'text/plain'], new ReadableIterableStream($this->drip())),
            'big' => new Response(200, ['content-type' => 'text/plain'], new ReadableIterableStream($this->big())),
            'basic' => $this->guard($request->getHeader('authorization') === 'Basic '.base64_encode(self::BASIC_USER.':'.self::BASIC_PASSWORD)),
            'bearer' => $this->guard($request->getHeader('authorization') === 'Bearer '.self::BEARER_TOKEN),
            'header' => $this->guard($request->getHeader(self::SECRET_HEADER) === self::SECRET_HEADER_VALUE),
            'mtls' => $this->guard($this->hasClientCertificate($request)),
            'fast' => new Response(200, ['content-type' => 'text/plain'], 'ok'),
            'headers' => new Response(200, ['content-type' => 'application/json'], json_encode($request->getHeaders(), JSON_THROW_ON_ERROR)),
            default => new Response(404),
        };
    }

    private function redirect(int $remaining): Response
    {
        return $remaining > 0
            ? new Response(301, ['location' => '/redirect/'.($remaining - 1)])
            : new Response(200, [], 'arrived');
    }

    private function hasClientCertificate(Request $request): bool
    {
        try {
            return ($request->getClient()->getTlsInfo()?->getPeerCertificates() ?? []) !== [];
        } catch (SocketException) {
            // Nothing is captured when the client sent no certificate.
            return false;
        }
    }

    private function guard(bool $allowed): Response
    {
        return $allowed ? new Response(200, [], 'welcome') : new Response(401, ['www-authenticate' => 'Basic realm="fixture"'], 'denied');
    }

    /**
     * @return \Generator<int, string>
     */
    private function drip(): \Generator
    {
        $cancellation = $this->stopping->getCancellation();
        try {
            for ($i = 0; $i < 600; $i++) {
                yield 'x';
                delay(1, cancellation: $cancellation);
            }
        } catch (CancelledException) {
            return;
        }
    }

    /**
     * @return \Generator<int, string>
     */
    private function big(): \Generator
    {
        $chunk = str_repeat('a', 65536);
        for ($offset = 0; $offset < 5 * 1048576; $offset += 65536) {
            yield $offset === 4 * 1048576 ? 'NEEDLE'.substr($chunk, 6) : $chunk;
        }
    }
}
