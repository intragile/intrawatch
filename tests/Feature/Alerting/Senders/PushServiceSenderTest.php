<?php

namespace Tests\Feature\Alerting\Senders;

use App\Alerting\Channels\SenderRegistry;
use App\Alerting\Channels\Senders\GotifySender;
use App\Alerting\Channels\Senders\MatrixSender;
use App\Alerting\Channels\Senders\NtfySender;
use App\Alerting\Channels\Senders\PushoverSender;
use App\Alerting\Channels\Senders\TelegramSender;
use App\Alerting\Transport\DeliveryFailure;
use App\Enums\AlertKind;
use App\Enums\ChannelType;
use GuzzleHttp\Psr7\Response;

class PushServiceSenderTest extends SenderTestCase
{
    private const TOKEN = '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdef0123';

    public function test_telegram_request(): void
    {
        $sender = new TelegramSender($this->http([new Response(200, [], '{"ok":true}')]));
        $spec = $this->spec(ChannelType::Telegram, ['chat_id' => '-100123', 'message_thread_id' => 9, 'server_url' => 'https://api.telegram.org/', 'silent' => true], ['bot_token' => self::TOKEN]);

        $sender->send($spec, $this->notification(body: str_repeat('x', 5000)), 'iw-1');

        $request = $this->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.telegram.org/bot'.self::TOKEN.'/sendMessage', (string) $request->getUri());
        $json = $this->lastJson();
        $this->assertSame('-100123', $json['chat_id']);
        $this->assertSame(9, $json['message_thread_id']);
        $this->assertTrue($json['disable_notification']);
        $this->assertSame(['is_disabled' => true], $json['link_preview_options']);
        $this->assertArrayNotHasKey('parse_mode', $json);
        $this->assertIsString($json['text']);
        $this->assertLessThanOrEqual(4096, strlen($json['text']));
        $this->assertStringStartsWith("[DOWN] Web\nxxx", $json['text']);
    }

    public function test_telegram_request_and_detail_redaction(): void
    {
        $body = '{"ok":false,"description":"Bad Request: chat not found at '.self::TOKEN.'"}';
        $sender = new TelegramSender($this->http([new Response(400, [], $body)]));
        $spec = $this->spec(ChannelType::Telegram, ['chat_id' => '-1'], ['bot_token' => self::TOKEN]);

        try {
            $sender->send($spec, $this->notification(), 'iw-1');
            $this->fail('Expected DeliveryFailure');
        } catch (DeliveryFailure $e) {
            $this->assertTrue($e->permanent);
            $this->assertStringContainsString('chat not found', $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
            $this->assertStringNotContainsString('ABCDEFGHIJKLMNOPQRSTUVWXYZ', $e->getMessage());
        }

        $sender = new TelegramSender($this->http([new Response(429, ['Retry-After' => '7'], 'not json')]));
        try {
            $sender->send($spec, $this->notification(), 'iw-1');
            $this->fail('Expected DeliveryFailure');
        } catch (DeliveryFailure $e) {
            $this->assertSame('HTTP 429', $e->getMessage());
            $this->assertSame(7, $e->retryAfter);
        }
    }

    public function test_push_services_map_severity_to_priority(): void
    {
        $cases = [
            [AlertKind::Down, 5, 8, 1],
            [AlertKind::Degraded, 4, 5, 0],
            [AlertKind::Up, 3, 2, 0],
            [AlertKind::Test, 3, 2, 0],
        ];
        foreach ($cases as [$kind, $ntfy, $gotify, $pushover]) {
            $n = $this->notificationOf($kind);

            (new NtfySender($this->http([new Response(200)])))->send($this->spec(ChannelType::Ntfy, ['topic' => 'alerts', 'server_url' => 'https://ntfy.example.test']), $n, 'k');
            $this->assertSame((string) $ntfy, $this->lastRequest()->getHeaderLine('Priority'));

            (new GotifySender($this->http([new Response(200)])))->send($this->spec(ChannelType::Gotify, ['server_url' => 'https://gotify.example.test'], ['app_token' => 'tok-abcdef']), $n, 'k');
            $this->assertSame($gotify, $this->lastJson()['priority']);

            (new PushoverSender($this->http([new Response(200)])))->send($this->spec(ChannelType::Pushover, [], ['app_token' => 'apptok', 'user_key' => 'userkey']), $n, 'k');
            parse_str((string) $this->lastRequest()->getBody(), $form);
            $this->assertSame((string) $pushover, $form['priority']);
        }
    }

    public function test_ntfy_request(): void
    {
        $sender = new NtfySender($this->http([new Response(200)]));
        $spec = $this->spec(ChannelType::Ntfy, ['topic' => 'my-alerts', 'server_url' => 'https://ntfy.example.test/'], ['access_token' => 'tk_secret']);

        $sender->send($spec, $this->notification(title: "[DOWN]\r\nWeb", body: 'Web is down'), 'iw-1');

        $request = $this->lastRequest();
        $this->assertSame('https://ntfy.example.test/my-alerts', (string) $request->getUri());
        $this->assertSame('Web is down', (string) $request->getBody());
        $this->assertSame('[DOWN] Web', $request->getHeaderLine('Title'));
        $this->assertSame('rotating_light', $request->getHeaderLine('Tags'));
        $this->assertSame('https://iw.test/monitors/42', $request->getHeaderLine('Click'));
        $this->assertSame('Bearer tk_secret', $request->getHeaderLine('Authorization'));

        $sender = new NtfySender($this->http([new Response(200)]));
        $sender->send($this->spec(ChannelType::Ntfy, ['topic' => 'a', 'server_url' => 'https://ntfy.example.test', 'priority' => 2]), $this->notificationOf(AlertKind::Up), 'iw-1');
        $this->assertSame('2', $this->lastRequest()->getHeaderLine('Priority'));
        $this->assertSame('white_check_mark', $this->lastRequest()->getHeaderLine('Tags'));
        $this->assertFalse($this->lastRequest()->hasHeader('Authorization'));
    }

    public function test_gotify_and_pushover_requests(): void
    {
        (new GotifySender($this->http([new Response(200)])))->send(
            $this->spec(ChannelType::Gotify, ['server_url' => 'https://gotify.example.test/'], ['app_token' => 'tok-abcdef']),
            $this->notification(),
            'iw-1',
        );
        $request = $this->lastRequest();
        $this->assertSame('https://gotify.example.test/message', (string) $request->getUri());
        $this->assertSame('tok-abcdef', $request->getHeaderLine('X-Gotify-Key'));
        $this->assertSame(['title' => '[DOWN] Web', 'message' => 'Web is down', 'priority' => 8], $this->lastJson());

        (new PushoverSender($this->http([new Response(200)])))->send(
            $this->spec(ChannelType::Pushover, ['sound' => 'siren', 'device' => 'phone'], ['app_token' => 'apptok', 'user_key' => 'userkey']),
            $this->notification(title: str_repeat('t', 300), body: str_repeat('b', 2000)),
            'iw-1',
        );
        $request = $this->lastRequest();
        $this->assertSame('https://api.pushover.net/1/messages.json', (string) $request->getUri());
        parse_str((string) $request->getBody(), $form);
        $this->assertSame('apptok', $form['token']);
        $this->assertSame('userkey', $form['user']);
        $this->assertIsString($form['title']);
        $this->assertIsString($form['message']);
        $this->assertLessThanOrEqual(250, strlen($form['title']));
        $this->assertLessThanOrEqual(1024, strlen($form['message']));
        $this->assertSame('siren', $form['sound']);
        $this->assertSame('phone', $form['device']);
        $this->assertSame('https://iw.test/monitors/42', $form['url']);
    }

    public function test_matrix_uses_idempotency_key_as_txn_id(): void
    {
        $sender = new MatrixSender($this->http([new Response(200, [], '{"event_id":"$e"}')]));
        $spec = $this->spec(ChannelType::Matrix, ['homeserver_url' => 'https://matrix.example.test', 'room_id' => '!room:example.test'], ['access_token' => 'syt_token']);

        $sender->send($spec, $this->notification(), 'iw-txn-5');

        $request = $this->lastRequest();
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('/_matrix/client/v3/rooms/%21room%3Aexample.test/send/m.room.message/iw-txn-5', $request->getUri()->getPath());
        $this->assertSame('Bearer syt_token', $request->getHeaderLine('Authorization'));
        $this->assertSame(['msgtype' => 'm.text', 'body' => "[DOWN] Web\nWeb is down"], $this->lastJson());
    }

    public function test_missing_credentials_are_permanent_and_registry_resolves_senders(): void
    {
        try {
            (new GotifySender($this->http([])))->send($this->spec(ChannelType::Gotify, ['server_url' => 'https://g.example.test']), $this->notification(), 'k');
            $this->fail('Expected DeliveryFailure');
        } catch (DeliveryFailure $e) {
            $this->assertTrue($e->permanent);
        }

        $registry = $this->app->make(SenderRegistry::class);
        foreach (ChannelType::cases() as $type) {
            $registry->for($type);
        }
        $this->assertInstanceOf(TelegramSender::class, $registry->for(ChannelType::Telegram));
    }

    public function test_telegram_redacts_before_cutting_and_drops_the_body(): void
    {
        $description = str_repeat('p', 290).' '.self::TOKEN.' tail';
        $body = json_encode(['ok' => false, 'description' => $description], JSON_THROW_ON_ERROR);
        $spec = $this->spec(ChannelType::Telegram, ['chat_id' => '-1'], ['bot_token' => self::TOKEN]);

        try {
            (new TelegramSender($this->http([new Response(400, [], $body)])))->send($spec, $this->notification(), 'k');
            $this->fail('Expected DeliveryFailure');
        } catch (DeliveryFailure $e) {
            for ($i = 0; $i + 6 <= strlen(self::TOKEN); $i++) {
                $this->assertStringNotContainsString(substr(self::TOKEN, $i, 6), $e->getMessage());
            }
            $this->assertNull($e->responseBody);
        }

        try {
            (new TelegramSender($this->http([new Response(400, [], '{"ok":false}')])))->send($spec, $this->notification(), 'k');
            $this->fail('Expected DeliveryFailure');
        } catch (DeliveryFailure $e) {
            $this->assertSame('HTTP 400', $e->getMessage());
            $this->assertNull($e->responseBody);
        }
    }
}
