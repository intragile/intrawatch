<?php

namespace Tests\Feature\Alerting\Senders;

use App\Alerting\Channels\ChannelSpec;
use App\Alerting\Messages\AlertMessage;
use App\Alerting\Messages\OutgoingNotification;
use App\Alerting\Transport\AddressResolver;
use App\Alerting\Transport\OutboundHttp;
use App\Enums\AlertKind;
use App\Enums\ChannelType;
use Carbon\CarbonImmutable;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

abstract class SenderTestCase extends TestCase
{
    /** @var list<RequestInterface> */
    protected array $history = [];

    /**
     * @param  list<Response>  $responses
     */
    protected function http(array $responses): OutboundHttp
    {
        $this->history = [];
        $this->app->instance(AddressResolver::class, new class implements AddressResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(fn (callable $next): callable => function (RequestInterface $request, array $options) use ($next) {
            $this->history[] = $request;

            return $next($request, $options);
        });

        return new OutboundHttp($this->app->make(AddressResolver::class), \Closure::fromCallable($stack));
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $secrets
     */
    protected function spec(ChannelType $type, array $config = [], array $secrets = []): ChannelSpec
    {
        return new ChannelSpec(1, 1, $type, $config, $secrets);
    }

    protected function notification(string $message = 'HTTP 503', string $title = '[DOWN] Web', string $body = 'Web is down'): OutgoingNotification
    {
        $item = new AlertMessage(
            AlertKind::Down, 'critical', 7, 'Acme', 42, 'Web', 'http', 'https://x.test', 'down', 'up',
            $message, CarbonImmutable::parse('2026-07-01 10:30:00', 'UTC'), null,
            [['key' => 'cert', 'old' => 'a', 'new' => 'b']], 'https://iw.test/monitors/42', $title, $body,
        );

        return new OutgoingNotification($title, $body, 'critical', [$item], false);
    }

    /**
     * @param  list<array{key: string, old: mixed, new: mixed}>  $changes
     */
    protected function notificationOf(AlertKind $kind, string $status = 'down', array $changes = []): OutgoingNotification
    {
        $severity = $kind->severity();
        $item = new AlertMessage(
            $kind, $severity, 7, 'Acme', 42, 'Web', 'http', 'https://x.test', $status, null,
            'msg', CarbonImmutable::parse('2026-07-01 10:30:00', 'UTC'), null,
            $changes, 'https://iw.test/monitors/42', '['.$kind->label().'] Web', 'Web body',
        );

        return new OutgoingNotification($item->title, $item->body, $severity, [$item], false);
    }

    protected function lastRequest(): RequestInterface
    {
        $request = end($this->history);
        $this->assertInstanceOf(RequestInterface::class, $request);

        return $request;
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function lastJson(): array
    {
        $decoded = json_decode((string) $this->lastRequest()->getBody(), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    protected function lastJsonText(string $key): string
    {
        $value = $this->lastJson()[$key] ?? null;
        $this->assertIsString($value);

        return $value;
    }
}
