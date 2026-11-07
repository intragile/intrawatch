<?php

declare(strict_types=1);

namespace Tests\Unit\Import\Kuma;

use App\Import\Kuma\Mapping\Mapped;
use App\Import\Kuma\Mapping\NotificationMapper;
use App\Import\Kuma\Mapping\Unmappable;
use App\Import\Kuma\Source\SourceFactory;
use PHPUnit\Framework\TestCase;

final class NotificationMapperTest extends TestCase
{
    private NotificationMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new NotificationMapper;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{id: int, name: string, active: bool, is_default: bool, config: array<string, mixed>}
     */
    private static function row(array $config, bool $active = true, bool $default = false): array
    {
        return ['id' => 7, 'name' => 'Channel', 'active' => $active, 'is_default' => $default, 'config' => $config];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function mapped(array $config): Mapped
    {
        $result = $this->mapper->map(self::row($config));
        $this->assertInstanceOf(Mapped::class, $result);

        return $result;
    }

    /**
     * @return list<string>
     */
    private static function codes(Mapped $m): array
    {
        return array_map(static fn ($n): string => $n->code, $m->notes);
    }

    public function test_smtp_fixture_maps_to_email(): void
    {
        $source = SourceFactory::open(dirname(__DIR__, 3).'/Fixtures/kuma/kuma2.db');
        $row = $source->notifications()[0];
        $source->close();

        $m = $this->mapper->map($row);

        $this->assertInstanceOf(Mapped::class, $m);
        $this->assertSame('email', $m->attributes['type']);
        $this->assertSame(['to' => ['ops@example.com'], 'smtp' => [
            'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'starttls', 'username' => 'u',
            'from_address' => 'kuma@example.com', 'from_name' => null,
        ]], $m->attributes['config']);
        $this->assertSame(['smtp_password' => 'p'], $m->attributes['secrets']);
        $this->assertTrue($m->attributes['is_default']);
        $this->assertTrue($m->attributes['active']);
    }

    public function test_smtp_details(): void
    {
        $m = $this->mapped([
            'type' => 'smtp', 'smtpHost' => 'h', 'smtpPort' => '465', 'smtpSecure' => true,
            'smtpFrom' => '"Kuma Alerts" <k@example.com>', 'smtpTo' => 'a@example.com; b@example.com,a@example.com',
            'smtpCC' => 'c@example.com', 'smtpBCC' => 'd@example.com', 'smtpDkimDomain' => 'example.com',
        ]);

        $config = $m->attributes['config'];
        $this->assertIsArray($config);
        $this->assertSame(['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com'], $config['to']);
        $smtp = $config['smtp'];
        $this->assertIsArray($smtp);
        $this->assertSame('tls', $smtp['encryption']);
        $this->assertSame(465, $smtp['port']);
        $this->assertSame('Kuma Alerts', $smtp['from_name']);
        $this->assertSame('k@example.com', $smtp['from_address']);
        $this->assertEqualsCanonicalizing(['smtp_cc_merged', 'smtp_template_ignored'], self::codes($m));
        $this->assertTrue($m->attributes['active']);
    }

    public function test_smtp_ignore_starttls_means_no_encryption(): void
    {
        $m = $this->mapped(['type' => 'smtp', 'smtpHost' => 'h', 'smtpFrom' => 'k@example.com', 'smtpTo' => 'a@example.com', 'smtpIgnoreSTARTTLS' => true]);

        $config = $m->attributes['config'];
        $this->assertIsArray($config);
        $smtp = $config['smtp'];
        $this->assertIsArray($smtp);
        $this->assertSame('none', $smtp['encryption']);
    }

    public function test_webhook_fixture_maps_with_secret_header(): void
    {
        $source = SourceFactory::open(dirname(__DIR__, 3).'/Fixtures/kuma/kuma2.db');
        $row = $source->notifications()[1];
        $source->close();

        $m = $this->mapper->map($row);

        $this->assertInstanceOf(Mapped::class, $m);
        $this->assertSame('webhook', $m->attributes['type']);
        $this->assertSame(['url' => 'https://hooks.example.com/kuma', 'method' => 'POST', 'format' => 'json', 'headers' => []], $m->attributes['config']);
        $this->assertSame(['secret_headers' => ['X-Token' => 'abc']], $m->attributes['secrets']);
        $this->assertSame([], $m->notes);
    }

    public function test_webhook_form_custom_and_plain_headers(): void
    {
        $form = $this->mapped(['type' => 'webhook', 'webhookURL' => 'https://x.example.com', 'webhookContentType' => 'form-data', 'webhookAdditionalHeaders' => '{"X-Env":"prod","Authorization":"Bearer s"}']);
        $config = $form->attributes['config'];
        $this->assertIsArray($config);
        $this->assertSame('form', $config['format']);
        $this->assertSame(['X-Env' => 'prod'], $config['headers']);
        $this->assertSame(['secret_headers' => ['Authorization' => 'Bearer s']], $form->attributes['secrets']);

        $custom = $this->mapped(['type' => 'webhook', 'webhookURL' => 'https://x.example.com', 'webhookContentType' => 'custom', 'httpMethod' => 'PATCH']);
        $config = $custom->attributes['config'];
        $this->assertIsArray($config);
        $this->assertSame('json', $config['format']);
        $this->assertSame('POST', $config['method']);
        $this->assertEqualsCanonicalizing(['webhook_custom_body_ignored', 'webhook_method_changed'], self::codes($custom));
    }

    public function test_webhook_invalid_header_json_is_inactive(): void
    {
        $m = $this->mapped(['type' => 'webhook', 'webhookURL' => 'https://x.example.com', 'webhookAdditionalHeaders' => '{nope']);

        $this->assertFalse($m->attributes['active']);
        $this->assertSame(['webhook_headers_invalid'], self::codes($m));
    }

    public function test_slack(): void
    {
        $m = $this->mapped(['type' => 'slack', 'slackwebhookURL' => 'https://hooks.slack.com/x', 'slackchannel' => '#ops']);

        $this->assertSame('slack', $m->attributes['type']);
        $this->assertSame(['webhook_url' => 'https://hooks.slack.com/x'], $m->attributes['secrets']);
        $this->assertSame(['slack_overrides_ignored'], self::codes($m));
    }

    public function test_discord(): void
    {
        $m = $this->mapped(['type' => 'discord', 'discordWebhookUrl' => 'https://discord.com/api/webhooks/1/x', 'discordUsername' => 'Kuma', 'threadId' => '123', 'discordPrefixMessage' => 'hi']);

        $this->assertSame(['username' => 'Kuma', 'thread_id' => '123'], $m->attributes['config']);
        $this->assertSame(['webhook_url' => 'https://discord.com/api/webhooks/1/x'], $m->attributes['secrets']);
        $this->assertSame(['discord_template_ignored'], self::codes($m));
    }

    public function test_teams(): void
    {
        $m = $this->mapped(['type' => 'teams', 'webhookUrl' => 'https://outlook.example.com/w']);

        $this->assertSame('teams', $m->attributes['type']);
        $this->assertSame(['webhook_url' => 'https://outlook.example.com/w'], $m->attributes['secrets']);
    }

    public function test_telegram(): void
    {
        $token = '123456:'.str_repeat('a', 35);
        $m = $this->mapped(['type' => 'telegram', 'telegramChatID' => '-100123', 'telegramMessageThreadID' => '5', 'telegramServerUrl' => 'https://api.telegram.org', 'telegramSendSilently' => true, 'telegramBotToken' => $token, 'telegramUseTemplate' => true]);

        $this->assertSame(['chat_id' => '-100123', 'message_thread_id' => 5, 'server_url' => 'https://api.telegram.org', 'silent' => true], $m->attributes['config']);
        $this->assertSame(['bot_token' => $token], $m->attributes['secrets']);
        $this->assertSame(['telegram_template_ignored'], self::codes($m));
    }

    public function test_ntfy_and_basic_auth(): void
    {
        $m = $this->mapped(['type' => 'ntfy', 'ntfyserverurl' => 'https://ntfy.sh', 'ntfytopic' => 'alerts', 'ntfyPriority' => 4, 'ntfyaccesstoken' => 'tk']);
        $this->assertSame(['server_url' => 'https://ntfy.sh', 'topic' => 'alerts', 'priority' => 4], $m->attributes['config']);
        $this->assertSame(['access_token' => 'tk'], $m->attributes['secrets']);
        $this->assertTrue($m->attributes['active']);

        $basic = $this->mapped(['type' => 'ntfy', 'ntfyserverurl' => 'https://ntfy.sh', 'ntfytopic' => 'alerts', 'ntfyAuthenticationMethod' => 'usernamePassword', 'ntfyusername' => 'u', 'ntfypassword' => 'pw']);
        $this->assertFalse($basic->attributes['active']);
        $this->assertSame(['ntfy_basic_auth_unsupported'], self::codes($basic));
        $this->assertSame([], $basic->attributes['secrets']);
    }

    public function test_gotify(): void
    {
        $m = $this->mapped(['type' => 'gotify', 'gotifyserverurl' => 'https://gotify.example.com', 'gotifyPriority' => 8, 'gotifyapplicationToken' => 'tok']);

        $this->assertSame(['server_url' => 'https://gotify.example.com', 'priority' => 8], $m->attributes['config']);
        $this->assertSame(['app_token' => 'tok'], $m->attributes['secrets']);
    }

    public function test_pushover(): void
    {
        $m = $this->mapped(['type' => 'pushover', 'pushoverpriority' => '1', 'pushoversounds' => 'cosmic', 'pushoverdevice' => 'phone', 'pushoveruserkey' => 'uk', 'pushoverapptoken' => 'at', 'pushovertitle' => 'T', 'pushoverttl' => '60']);

        $this->assertSame(['priority' => 1, 'sound' => 'cosmic', 'device' => 'phone'], $m->attributes['config']);
        $this->assertSame(['user_key' => 'uk', 'app_token' => 'at'], $m->attributes['secrets']);
        $this->assertSame(['pushover_options_ignored'], self::codes($m));
    }

    public function test_matrix(): void
    {
        $m = $this->mapped(['type' => 'matrix', 'homeserverUrl' => 'https://matrix.example.com', 'internalRoomId' => '!r:example.com', 'accessToken' => 'syt']);

        $this->assertSame(['homeserver_url' => 'https://matrix.example.com', 'room_id' => '!r:example.com'], $m->attributes['config']);
        $this->assertSame(['access_token' => 'syt'], $m->attributes['secrets']);
    }

    public function test_mattermost(): void
    {
        $m = $this->mapped(['type' => 'mattermost', 'mattermostWebhookUrl' => 'https://mm.example.com/hooks/x', 'mattermostchannel' => 'town', 'mattermostusername' => 'kuma', 'mattermosticonemo' => ':ok:']);

        $this->assertSame(['channel' => 'town', 'username' => 'kuma'], $m->attributes['config']);
        $this->assertSame(['webhook_url' => 'https://mm.example.com/hooks/x'], $m->attributes['secrets']);
        $this->assertSame(['mattermost_icon_ignored'], self::codes($m));
    }

    public function test_google_chat(): void
    {
        $m = $this->mapped(['type' => 'GoogleChat', 'googleChatWebhookURL' => 'https://chat.googleapis.com/v1/x']);

        $this->assertSame('google_chat', $m->attributes['type']);
        $this->assertSame(['webhook_url' => 'https://chat.googleapis.com/v1/x'], $m->attributes['secrets']);
    }

    public function test_pagerduty(): void
    {
        $key = str_repeat('k', 32);
        $m = $this->mapped(['type' => 'PagerDuty', 'pagerdutyIntegrationKey' => $key, 'pagerdutyIntegrationUrl' => 'https://pd.example.com/enqueue', 'pagerdutyPriority' => 'critical']);

        $this->assertSame('pagerduty', $m->attributes['type']);
        $this->assertSame(['routing_key' => $key], $m->attributes['secrets']);
        $this->assertSame(['pagerduty_options_ignored'], self::codes($m));

        $plain = $this->mapped(['type' => 'PagerDuty', 'pagerdutyIntegrationKey' => $key, 'pagerdutyIntegrationUrl' => 'https://events.pagerduty.com/v2/enqueue']);
        $this->assertSame([], $plain->notes);
    }

    public function test_opsgenie(): void
    {
        $m = $this->mapped(['type' => 'Opsgenie', 'opsgenieRegion' => 'eu', 'opsgeniePriority' => 2, 'opsgenieApiKey' => 'ak']);

        $this->assertSame('opsgenie', $m->attributes['type']);
        $this->assertSame(['region' => 'eu', 'priority' => 'P2'], $m->attributes['config']);
        $this->assertSame(['api_key' => 'ak'], $m->attributes['secrets']);
    }

    public function test_apprise_is_always_inactive(): void
    {
        $m = $this->mapped(['type' => 'apprise', 'appriseURL' => 'json://localhost']);

        $this->assertSame('apprise', $m->attributes['type']);
        $this->assertSame(['urls' => 'json://localhost'], $m->attributes['secrets']);
        $this->assertFalse($m->attributes['active']);
        $this->assertSame(['apprise_server_required'], self::codes($m));
    }

    public function test_source_flags_are_carried(): void
    {
        $m = $this->mapper->map(self::row(['type' => 'teams', 'webhookUrl' => 'https://x.example.com'], active: false, default: true));

        $this->assertInstanceOf(Mapped::class, $m);
        $this->assertFalse($m->attributes['active']);
        $this->assertTrue($m->attributes['is_default']);
    }

    public function test_unsupported_provider_is_skipped_with_type_name(): void
    {
        $result = $this->mapper->map(self::row(['type' => 'LineNotify']));

        $this->assertInstanceOf(Unmappable::class, $result);
        $this->assertSame([], $result->errors);
        $this->assertSame('provider_unsupported', $result->reason->code);
        $this->assertStringContainsString('LineNotify', $result->reason->message);
    }

    public function test_missing_required_value_is_invalid_and_not_invented(): void
    {
        $result = $this->mapper->map(self::row(['type' => 'slack', 'slackwebhookURL' => '  ']));

        $this->assertInstanceOf(Unmappable::class, $result);
        $this->assertArrayHasKey('secrets.webhook_url', $result->errors);
    }

    public function test_long_name_is_shortened(): void
    {
        $row = self::row(['type' => 'teams', 'webhookUrl' => 'https://x.example.com']);
        $row['name'] = str_repeat('n', 130);

        $m = $this->mapper->map($row);

        $this->assertInstanceOf(Mapped::class, $m);
        $name = $m->attributes['name'];
        $this->assertIsString($name);
        $this->assertSame(120, mb_strlen($name));
        $this->assertSame(['channel_name_truncated'], self::codes($m));
    }
}
