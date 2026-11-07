<?php

namespace Tests\Feature\Alerting\Senders;

use App\Alerting\Channels\Senders\AppriseSender;
use App\Alerting\Transport\DeliveryFailure;
use App\Enums\AlertKind;
use App\Enums\ChannelType;
use GuzzleHttp\Psr7\Response;

class AppriseSenderTest extends SenderTestCase
{
    public function test_apprise_with_config_key_or_urls(): void
    {
        $sender = new AppriseSender($this->http([new Response(200)]));
        $sender->send($this->spec(ChannelType::Apprise, ['server_url' => 'https://apprise.example.test/', 'config_key' => 'team-a']), $this->notification(), 'k');

        $this->assertSame('https://apprise.example.test/notify/team-a', (string) $this->lastRequest()->getUri());
        $this->assertSame(['title' => '[DOWN] Web', 'body' => 'Web is down', 'type' => 'failure'], $this->lastJson());

        $sender = new AppriseSender($this->http([new Response(200)]));
        $sender->send(
            $this->spec(ChannelType::Apprise, ['server_url' => 'https://apprise.example.test'], ['urls' => 'tgram://token/chat']),
            $this->notificationOf(AlertKind::Up, 'up'),
            'k',
        );

        $this->assertSame('https://apprise.example.test/notify/', (string) $this->lastRequest()->getUri());
        $json = $this->lastJson();
        $this->assertSame('tgram://token/chat', $json['urls']);
        $this->assertSame('success', $json['type']);

        $sender = new AppriseSender($this->http([new Response(200)]));
        $sender->send($this->spec(ChannelType::Apprise, ['server_url' => 'https://apprise.example.test', 'config_key' => 'k1']), $this->notificationOf(AlertKind::Degraded, 'degraded'), 'k');
        $this->assertSame('warning', $this->lastJson()['type']);
    }

    public function test_neither_key_nor_urls_is_permanent(): void
    {
        try {
            (new AppriseSender($this->http([])))->send($this->spec(ChannelType::Apprise, ['server_url' => 'https://apprise.example.test']), $this->notification(), 'k');
            $this->fail('Expected DeliveryFailure');
        } catch (DeliveryFailure $e) {
            $this->assertTrue($e->permanent);
        }
    }
}
