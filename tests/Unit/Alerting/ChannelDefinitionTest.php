<?php

namespace Tests\Unit\Alerting;

use App\Alerting\Channels\ChannelRegistry;
use App\Alerting\Engine\AlertClass;
use App\Enums\AlertKind;
use App\Enums\ChannelType;
use App\Enums\MonitorStatus;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Validation\Validator;
use Tests\TestCase;

class ChannelDefinitionTest extends TestCase
{
    /**
     * Validate a channel the way the request layer does: defaults merged under the input, rules prefixed with
     * `config.` / `secrets.`, then the cross-field hook.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $secrets
     */
    private function validate(ChannelType $type, array $config, array $secrets = []): Validator
    {
        $definition = $type->definition();
        $config = array_merge($definition->defaults(), $config);

        $rules = [];
        foreach ($definition->configRules() as $key => $rule) {
            $rules['config.'.$key] = $rule;
        }
        foreach ($definition->secretRules() as $key => $rule) {
            $rules['secrets.'.$key] = $rule;
        }

        $validator = ValidatorFactory::make(['config' => $config, 'secrets' => $secrets], $rules);
        $validator->after(fn (Validator $v) => $definition->afterValidation($config, $secrets, $v));
        $validator->passes();

        return $validator;
    }

    public function test_every_channel_type_has_a_definition(): void
    {
        foreach (ChannelType::cases() as $type) {
            $this->assertSame($type, $type->definition()->type());
        }
        $this->assertCount(15, ChannelType::cases());
        $this->assertCount(15, ChannelRegistry::all());
        $this->assertFalse(ChannelType::PagerDuty->definition()->supportsDigest());
        $this->assertFalse(ChannelType::Opsgenie->definition()->supportsDigest());
        $this->assertFalse(ChannelType::Webhook->definition()->supportsDigest());
        $this->assertTrue(ChannelType::Slack->definition()->supportsDigest());
        $this->assertTrue(ChannelType::Email->definition()->supportsDigest());
    }

    public function test_alert_class_mapping(): void
    {
        $this->assertSame(AlertClass::Ok, AlertClass::of(MonitorStatus::Up, true));
        $this->assertSame(AlertClass::Ok, AlertClass::of(MonitorStatus::Degraded, false));
        $this->assertSame(AlertClass::Degraded, AlertClass::of(MonitorStatus::Degraded, true));
        $this->assertSame(AlertClass::Down, AlertClass::of(MonitorStatus::Down, false));
        $this->assertNull(AlertClass::of(MonitorStatus::Pending, true));
        $this->assertNull(AlertClass::of(MonitorStatus::Maintenance, true));
        $this->assertNull(AlertClass::of(MonitorStatus::Paused, true));
    }

    public function test_alert_kind_labels_and_severity(): void
    {
        $this->assertSame('STILL DOWN', AlertKind::StillDown->label());
        $this->assertSame('TEST', AlertKind::Test->label());
        $this->assertSame('critical', AlertKind::Down->severity());
        $this->assertSame('critical', AlertKind::StillDown->severity());
        foreach ([AlertKind::Degraded, AlertKind::Flapping, AlertKind::Expiring, AlertKind::Changed] as $kind) {
            $this->assertSame('warning', $kind->severity());
        }
        foreach ([AlertKind::Up, AlertKind::Stable, AlertKind::Renewed] as $kind) {
            $this->assertSame('ok', $kind->severity());
        }
        $this->assertSame('info', AlertKind::Test->severity());
    }

    public function test_valid_minimal_configs_pass(): void
    {
        $this->assertTrue($this->validate(ChannelType::Slack, [], ['webhook_url' => 'https://hooks.slack.test/T/B/x'])->passes());
        $this->assertTrue($this->validate(ChannelType::Email, ['to' => ['ops@example.test']])->passes());
        $this->assertTrue($this->validate(ChannelType::Webhook, ['url' => 'https://example.test/hook'])->passes());
        $this->assertTrue($this->validate(ChannelType::Telegram, ['chat_id' => '-100123'], ['bot_token' => '123456:'.str_repeat('a', 35)])->passes());
        $this->assertTrue($this->validate(ChannelType::Telegram, ['chat_id' => '@my_channel'], ['bot_token' => '123456:'.str_repeat('a', 35)])->passes());
        $this->assertTrue($this->validate(ChannelType::Ntfy, ['topic' => 'alerts'])->passes());
        $this->assertTrue($this->validate(ChannelType::Matrix, ['homeserver_url' => 'https://m.test', 'room_id' => '!abc:m.test'], ['access_token' => 't'])->passes());
        $this->assertTrue($this->validate(ChannelType::PagerDuty, [], ['routing_key' => str_repeat('a', 32)])->passes());
        $this->assertTrue($this->validate(ChannelType::Opsgenie, [], ['api_key' => 'k'])->passes());
        $this->assertTrue($this->validate(ChannelType::Apprise, ['server_url' => 'https://apprise.test', 'config_key' => 'team'])->passes());
        $this->assertTrue($this->validate(ChannelType::Apprise, ['server_url' => 'https://apprise.test'], ['urls' => 'mailto://x'])->passes());
    }

    public function test_definitions_reject_bad_input(): void
    {
        $token = '123456:'.str_repeat('a', 35);

        $v = $this->validate(ChannelType::Telegram, ['chat_id' => 'abc'], ['bot_token' => $token]);
        $this->assertTrue($v->errors()->has('config.chat_id'));

        $v = $this->validate(ChannelType::Telegram, ['chat_id' => '1'], ['bot_token' => 'x']);
        $this->assertTrue($v->errors()->has('secrets.bot_token'));

        $v = $this->validate(ChannelType::Webhook, ['url' => 'https://u:p@x.test']);
        $this->assertTrue($v->errors()->has('config.url'));

        $v = $this->validate(ChannelType::Webhook, ['url' => 'ftp://x.test']);
        $this->assertTrue($v->errors()->has('config.url'));

        $v = $this->validate(ChannelType::Slack, [], ['webhook_url' => 'https://u:p@hooks.test/x']);
        $this->assertTrue($v->errors()->has('secrets.webhook_url'));

        $v = $this->validate(ChannelType::Slack, [], []);
        $this->assertTrue($v->errors()->has('secrets.webhook_url'));

        $v = $this->validate(ChannelType::Email, ['to' => []]);
        $this->assertTrue($v->errors()->has('config.to'));

        $many = array_map(fn (int $i): string => "u{$i}@example.test", range(1, 21));
        $v = $this->validate(ChannelType::Email, ['to' => $many]);
        $this->assertTrue($v->errors()->has('config.to'));

        $v = $this->validate(ChannelType::Apprise, ['server_url' => 'https://apprise.test']);
        $this->assertTrue($v->errors()->isNotEmpty());

        $v = $this->validate(ChannelType::Matrix, ['homeserver_url' => 'https://m.test', 'room_id' => '#alias:x'], ['access_token' => 't']);
        $this->assertTrue($v->errors()->has('config.room_id'));

        $v = $this->validate(ChannelType::PagerDuty, [], ['routing_key' => 'short']);
        $this->assertTrue($v->errors()->has('secrets.routing_key'));

        $v = $this->validate(ChannelType::Opsgenie, ['region' => 'asia'], ['api_key' => 'k']);
        $this->assertTrue($v->errors()->has('config.region'));
    }

    public function test_required_secrets_are_declared(): void
    {
        $this->assertSame(['webhook_url'], ChannelType::Slack->definition()->requiredSecrets());
        $this->assertSame(['user_key', 'app_token'], ChannelType::Pushover->definition()->requiredSecrets());
        $this->assertSame([], ChannelType::Email->definition()->requiredSecrets());
    }
}
