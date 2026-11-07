<?php

namespace Tests\Feature\Alerting\Senders;

use App\Alerting\Channels\Senders\WebhookSender;
use App\Alerting\Transport\DeliveryFailure;
use App\Enums\ChannelType;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Arr;

class WebhookSenderTest extends SenderTestCase
{
    public function test_webhook_payload_and_signature(): void
    {
        $sender = new WebhookSender($this->http([new Response(200)]));
        $spec = $this->spec(
            ChannelType::Webhook,
            ['url' => 'https://hooks.example.test/in', 'method' => 'PUT', 'format' => 'json', 'headers' => ['X-Env' => 'prod']],
            ['signing_secret' => 'a-long-signing-secret', 'secret_headers' => ['Authorization' => 'Bearer tok-123456']],
        );

        $sender->send($spec, $this->notification(), 'iw-99');

        $request = $this->lastRequest();
        $raw = (string) $request->getBody();
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame([
            'event' => 'down',
            'severity' => 'critical',
            'title' => '[DOWN] Web',
            'body' => 'Web is down',
            'monitor' => ['id' => 42, 'name' => 'Web', 'type' => 'http', 'target' => 'https://x.test', 'url' => 'https://iw.test/monitors/42'],
            'status' => 'down',
            'previous_status' => 'up',
            'message' => 'HTTP 503',
            'at' => '2026-07-01T10:30:00Z',
            'duration_seconds' => null,
            'changes' => [['key' => 'cert', 'old' => 'a', 'new' => 'b']],
            'workspace' => ['id' => 7, 'name' => 'Acme'],
        ], $this->lastJson());

        $this->assertSame('down', $request->getHeaderLine('X-IntraWatch-Event'));
        $this->assertSame('iw-99', $request->getHeaderLine('X-IntraWatch-Delivery'));
        $this->assertSame('prod', $request->getHeaderLine('X-Env'));
        $this->assertSame('Bearer tok-123456', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $signature = $request->getHeaderLine('X-IntraWatch-Signature');
        $this->assertMatchesRegularExpression('/^t=\d+,v1=[0-9a-f]{64}$/', $signature);
        $t = substr((string) strstr($signature, ',', true), 2);
        $this->assertSame("t={$t},v1=".hash_hmac('sha256', "{$t}.{$raw}", 'a-long-signing-secret'), $signature);
    }

    public function test_form_format_and_no_signature_without_secret(): void
    {
        $sender = new WebhookSender($this->http([new Response(204)]));
        $sender->send($this->spec(ChannelType::Webhook, ['url' => 'https://hooks.example.test/in', 'format' => 'form']), $this->notification(), 'iw-1');

        $request = $this->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        parse_str((string) $request->getBody(), $fields);
        $this->assertSame('down', $fields['event'] ?? null);
        $this->assertSame('Web', Arr::get($fields, 'monitor.name'));
        $this->assertFalse($request->hasHeader('X-IntraWatch-Signature'));
    }

    public function test_failures_never_echo_the_webhook_url(): void
    {
        $sender = new WebhookSender($this->http([new Response(404, [], 'https://hooks.example.test/in?token=SECRETTOKEN')]));

        try {
            $sender->send($this->spec(ChannelType::Webhook, ['url' => 'https://hooks.example.test/in?token=SECRETTOKEN']), $this->notification(), 'iw-1');
            $this->fail('Expected DeliveryFailure');
        } catch (DeliveryFailure $e) {
            $this->assertSame('HTTP 404', $e->getMessage());
            $this->assertTrue($e->permanent);
        }
    }

    public function test_custom_headers_cannot_duplicate_or_override_framework_headers(): void
    {
        $sender = new WebhookSender($this->http([new Response(200)]));
        $spec = $this->spec(
            ChannelType::Webhook,
            ['url' => 'https://hooks.example.test/in', 'headers' => ['content-type' => 'text/plain', 'x-intrawatch-event' => 'fake', 'X-Keep' => 'yes']],
            ['signing_secret' => 'a-long-signing-secret', 'secret_headers' => ['X-INTRAWATCH-SIGNATURE' => 'forged', 'X-IntraWatch-Delivery' => 'forged']],
        );

        $sender->send($spec, $this->notification(), 'iw-9');

        $request = $this->lastRequest();
        $this->assertSame(['application/json'], $request->getHeader('Content-Type'));
        $this->assertSame(['down'], $request->getHeader('X-IntraWatch-Event'));
        $this->assertSame(['iw-9'], $request->getHeader('X-IntraWatch-Delivery'));
        $this->assertCount(1, $request->getHeader('X-IntraWatch-Signature'));
        $this->assertStringStartsWith('t=', $request->getHeaderLine('X-IntraWatch-Signature'));
        $this->assertSame('yes', $request->getHeaderLine('X-Keep'));
    }
}
