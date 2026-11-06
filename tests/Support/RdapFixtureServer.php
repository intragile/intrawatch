<?php

namespace Tests\Support;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\BindContext;
use Amp\Socket\Certificate;
use Amp\Socket\InternetAddress;
use Amp\Socket\ServerTlsContext;
use Psr\Log\NullLogger;

use function Amp\delay;

/**
 * A scripted RDAP server on 127.0.0.1. Every request path is logged; unscripted domains answer 404.
 */
final class RdapFixtureServer
{
    private readonly SocketHttpServer $server;

    private readonly int $port;

    /** @var array<string, array{status: int, body: string, delay: float, headers: array<non-empty-string, string>}> */
    private array $routes = [];

    /** @var list<string> */
    public array $requests = [];

    /** Most requests that were being handled at the same time. */
    public int $maxConcurrent = 0;

    private int $active = 0;

    private bool $stopped = false;

    public function __construct(?TestCertificates $certificates = null)
    {
        $this->server = SocketHttpServer::createForDirectAccess(new NullLogger, enableCompression: false, connectionLimitPerIp: 1000);
        $this->server->expose('127.0.0.1:0', $certificates === null
            ? null
            : (new BindContext)->withTlsContext((new ServerTlsContext)->withDefaultCertificate(new Certificate($certificates->serverFile))));
        $this->server->start(new ClosureRequestHandler($this->handle(...)), new DefaultErrorHandler);

        $address = $this->server->getServers()[0]->getAddress();
        assert($address instanceof InternetAddress);
        $this->port = $address->getPort();
    }

    public function port(): int
    {
        return $this->port;
    }

    /**
     * @param  array<string, mixed>|string|null  $body  A JSON document, or a raw body.
     * @param  array<non-empty-string, string>  $headers
     */
    public function respond(string $path, int $status, array|string|null $body = null, float $delay = 0.0, array $headers = []): void
    {
        $this->routes[$path] = [
            'status' => $status,
            'body' => is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : (string) $body,
            'delay' => $delay,
            'headers' => $headers + ['content-type' => 'application/rdap+json'],
        ];
    }

    /**
     * An RDAP domain object with an expiration event and a registrar entity.
     *
     * @return array<string, mixed>
     */
    public static function domain(string $name, ?string $expires, string $registrar = 'Example Registrar, Inc.'): array
    {
        $events = [['eventAction' => 'registration', 'eventDate' => '2001-02-03T04:05:06Z']];
        if ($expires !== null) {
            $events[] = ['eventAction' => 'expiration', 'eventDate' => $expires];
        }

        return [
            'objectClassName' => 'domain',
            'ldhName' => strtoupper($name),
            'events' => $events,
            'entities' => [
                ['objectClassName' => 'entity', 'roles' => ['technical'], 'vcardArray' => ['vcard', [['version', [], 'text', '4.0'], ['fn', [], 'text', 'Not The Registrar']]]],
                ['objectClassName' => 'entity', 'roles' => ['registrar'], 'vcardArray' => ['vcard', [['version', [], 'text', '4.0'], ['fn', [], 'text', $registrar]]]],
            ],
        ];
    }

    public function stop(): void
    {
        if (! $this->stopped) {
            $this->stopped = true;
            $this->server->stop();
        }
    }

    private function handle(Request $request): Response
    {
        $path = $request->getUri()->getPath();
        $this->requests[] = $path;

        $route = $this->routes[$path] ?? null;
        if ($route === null) {
            return new Response(404, ['content-type' => 'application/rdap+json'], '{"errorCode":404}');
        }

        $this->maxConcurrent = max($this->maxConcurrent, ++$this->active);
        try {
            if ($route['delay'] > 0) {
                delay($route['delay']);
            }

            return new Response($route['status'], $route['headers'], $route['body']);
        } finally {
            $this->active--;
        }
    }
}
