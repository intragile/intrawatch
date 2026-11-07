<?php

namespace Tests\Feature\Alerting\Senders;

use App\Alerting\Channels\SenderRegistry;
use App\Alerting\Channels\Senders\DiscordSender;
use App\Alerting\Channels\Senders\GoogleChatSender;
use App\Alerting\Channels\Senders\MattermostSender;
use App\Alerting\Channels\Senders\SlackSender;
use App\Alerting\Channels\Senders\TeamsSender;
use App\Alerting\Transport\DeliveryFailure;
use App\Enums\ChannelType;
use GuzzleHttp\Psr7\Response;

class ChatWebhookSenderTest extends SenderTestCase
{
    private const URL = 'https://hooks.slack.test/services/T000/B000/SECRETSECRET';

    public function test_slack_discord_teams_mattermost_google_chat_payloads(): void
    {
        $n = $this->notification();
        $secrets = ['webhook_url' => self::URL];

        (new SlackSender($this->http([new Response(200)])))->send($this->spec(ChannelType::Slack, [], $secrets), $n, 'iw-1');
        $this->assertSame(['text' => "[DOWN] Web\nWeb is down"], $this->lastJson());
        $this->assertSame(self::URL, (string) $this->lastRequest()->getUri());

        (new GoogleChatSender($this->http([new Response(200)])))->send($this->spec(ChannelType::GoogleChat, [], $secrets), $n, 'iw-1');
        $this->assertSame(['text' => "[DOWN] Web\nWeb is down"], $this->lastJson());

        (new MattermostSender($this->http([new Response(200)])))->send(
            $this->spec(ChannelType::Mattermost, ['channel' => 'ops', 'username' => 'iw'], $secrets), $n, 'iw-1');
        $this->assertSame(['text' => "[DOWN] Web\nWeb is down", 'channel' => 'ops', 'username' => 'iw'], $this->lastJson());

        (new MattermostSender($this->http([new Response(200)])))->send($this->spec(ChannelType::Mattermost, ['channel' => null], $secrets), $n, 'iw-1');
        $this->assertSame(['text' => "[DOWN] Web\nWeb is down"], $this->lastJson());

        (new DiscordSender($this->http([new Response(204)])))->send(
            $this->spec(ChannelType::Discord, ['username' => 'iw', 'thread_id' => '123'], $secrets), $n, 'iw-1');
        $this->assertSame(
            ['content' => "[DOWN] Web\nWeb is down", 'allowed_mentions' => ['parse' => []], 'username' => 'iw'],
            $this->lastJson(),
        );
        $this->assertSame(self::URL.'?thread_id=123', (string) $this->lastRequest()->getUri());

        (new TeamsSender($this->http([new Response(202)])))->send($this->spec(ChannelType::Teams, [], $secrets), $n, 'iw-1');
        $this->assertSame([
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => [
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => [
                        ['type' => 'TextBlock', 'text' => '[DOWN] Web', 'weight' => 'Bolder', 'wrap' => true],
                        ['type' => 'TextBlock', 'text' => 'Web is down', 'wrap' => true],
                    ],
                ],
            ]],
        ], $this->lastJson());
    }

    public function test_target_text_cannot_mention_or_format(): void
    {
        $n = $this->notification(body: 'Down: @everyone <!channel> <b>x</b> & <https://evil.test|click>');
        $secrets = ['webhook_url' => self::URL];

        (new SlackSender($this->http([new Response(200)])))->send($this->spec(ChannelType::Slack, [], $secrets), $n, 'iw-1');
        $text = $this->lastJsonText('text');
        $this->assertStringContainsString('&lt;!channel&gt;', $text);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt; &amp; &lt;https://evil.test|click&gt;', $text);
        $this->assertStringNotContainsString('<', $text);

        (new MattermostSender($this->http([new Response(200)])))->send($this->spec(ChannelType::Mattermost, [], $secrets), $n, 'iw-1');
        $this->assertStringContainsString('&lt;!channel&gt;', $this->lastJsonText('text'));

        (new DiscordSender($this->http([new Response(204)])))->send($this->spec(ChannelType::Discord, [], $secrets), $n, 'iw-1');
        $this->assertSame(['parse' => []], $this->lastJson()['allowed_mentions']);
    }

    public function test_discord_content_is_capped_without_splitting_characters(): void
    {
        $n = $this->notification(body: str_repeat('é', 3000));
        (new DiscordSender($this->http([new Response(204)])))->send($this->spec(ChannelType::Discord, [], ['webhook_url' => self::URL]), $n, 'iw-1');

        $content = $this->lastJsonText('content');
        $this->assertLessThanOrEqual(2000, strlen($content));
        $this->assertTrue(mb_check_encoding($content, 'UTF-8'));
    }

    public function test_failures_never_echo_the_webhook_url(): void
    {
        $sender = new SlackSender($this->http([new Response(404, [], 'no such hook '.self::URL)]));

        try {
            $sender->send($this->spec(ChannelType::Slack, [], ['webhook_url' => self::URL]), $this->notification(), 'iw-1');
            $this->fail('Expected DeliveryFailure');
        } catch (DeliveryFailure $e) {
            $this->assertSame('HTTP 404', $e->getMessage());
            $this->assertStringNotContainsString('SECRETSECRET', $e->getMessage());
            $this->assertTrue($e->permanent);
        }

        $sender = new SlackSender($this->http([new Response(500, ['Retry-After' => '30'])]));
        try {
            $sender->send($this->spec(ChannelType::Slack, [], ['webhook_url' => self::URL]), $this->notification(), 'iw-1');
            $this->fail('Expected DeliveryFailure');
        } catch (DeliveryFailure $e) {
            $this->assertFalse($e->permanent);
            $this->assertSame(30, $e->retryAfter);
        }
    }

    public function test_missing_webhook_url_is_permanent_and_registry_resolves_senders(): void
    {
        try {
            (new SlackSender($this->http([])))->send($this->spec(ChannelType::Slack), $this->notification(), 'iw-1');
            $this->fail('Expected DeliveryFailure');
        } catch (DeliveryFailure $e) {
            $this->assertTrue($e->permanent);
        }

        $registry = $this->app->make(SenderRegistry::class);
        $this->assertInstanceOf(SlackSender::class, $registry->for(ChannelType::Slack));
        $this->assertInstanceOf(TeamsSender::class, $registry->for(ChannelType::Teams));
    }

    public function test_google_chat_text_is_inert(): void
    {
        $n = $this->notification(body: '<users/all> <https://evil.test|label> *bold* _it_ ~s~ `c`');
        (new GoogleChatSender($this->http([new Response(200)])))->send($this->spec(ChannelType::GoogleChat, [], ['webhook_url' => self::URL]), $n, 'iw-1');

        $text = $this->lastJsonText('text');
        $this->assertStringNotContainsString('<', $text);
        $this->assertStringContainsString('&lt;users/all&gt;', $text);
        $this->assertStringContainsString("\u{200B}*bold\u{200B}*", $text);
        $this->assertStringContainsString("\u{200B}_it\u{200B}_", $text);
        $this->assertStringContainsString("\u{200B}~s\u{200B}~", $text);
        $this->assertStringContainsString("\u{200B}`c\u{200B}`", $text);
    }

    public function test_plain_mentions_cannot_fire_on_any_chat_channel(): void
    {
        $n = $this->notification(title: '@here down', body: '@channel @all @here @everyone');
        $secrets = ['webhook_url' => self::URL];
        $senders = [
            [ChannelType::Slack, SlackSender::class],
            [ChannelType::Mattermost, MattermostSender::class],
            [ChannelType::Discord, DiscordSender::class],
            [ChannelType::GoogleChat, GoogleChatSender::class],
        ];

        foreach ($senders as [$type, $class]) {
            (new $class($this->http([new Response(200)])))->send($this->spec($type, [], $secrets), $n, 'iw-1');
            $text = $this->lastJsonText($type === ChannelType::Discord ? 'content' : 'text');
            $this->assertMentionsInert($text, $type->value);
        }

        (new TeamsSender($this->http([new Response(200)])))->send($this->spec(ChannelType::Teams, [], $secrets), $n, 'iw-1');
        $this->assertMentionsInert((string) json_encode($this->lastJson(), JSON_UNESCAPED_UNICODE), 'teams');
    }

    private function assertMentionsInert(string $text, string $label): void
    {
        foreach (['@channel', '@all', '@here', '@everyone'] as $mention) {
            $this->assertStringNotContainsString($mention, $text, "{$label}: {$mention}");
        }
        $this->assertStringContainsString("@\u{200B}", $text);
    }
}
