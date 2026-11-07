<?php

namespace App\Alerting\Channels;

use App\Alerting\Channels\Definitions\AppriseDefinition;
use App\Alerting\Channels\Definitions\DiscordDefinition;
use App\Alerting\Channels\Definitions\EmailDefinition;
use App\Alerting\Channels\Definitions\GoogleChatDefinition;
use App\Alerting\Channels\Definitions\GotifyDefinition;
use App\Alerting\Channels\Definitions\MatrixDefinition;
use App\Alerting\Channels\Definitions\MattermostDefinition;
use App\Alerting\Channels\Definitions\NtfyDefinition;
use App\Alerting\Channels\Definitions\OpsgenieDefinition;
use App\Alerting\Channels\Definitions\PagerDutyDefinition;
use App\Alerting\Channels\Definitions\PushoverDefinition;
use App\Alerting\Channels\Definitions\SlackDefinition;
use App\Alerting\Channels\Definitions\TeamsDefinition;
use App\Alerting\Channels\Definitions\TelegramDefinition;
use App\Alerting\Channels\Definitions\WebhookDefinition;
use App\Enums\ChannelType;

final class ChannelRegistry
{
    /** @var array<string, ChannelDefinition> */
    private static array $instances = [];

    public static function for(ChannelType $type): ChannelDefinition
    {
        return self::$instances[$type->value] ??= match ($type) {
            ChannelType::Email => new EmailDefinition,
            ChannelType::Webhook => new WebhookDefinition,
            ChannelType::Slack => new SlackDefinition,
            ChannelType::Discord => new DiscordDefinition,
            ChannelType::Teams => new TeamsDefinition,
            ChannelType::Mattermost => new MattermostDefinition,
            ChannelType::GoogleChat => new GoogleChatDefinition,
            ChannelType::Telegram => new TelegramDefinition,
            ChannelType::Ntfy => new NtfyDefinition,
            ChannelType::Gotify => new GotifyDefinition,
            ChannelType::Pushover => new PushoverDefinition,
            ChannelType::Matrix => new MatrixDefinition,
            ChannelType::PagerDuty => new PagerDutyDefinition,
            ChannelType::Opsgenie => new OpsgenieDefinition,
            ChannelType::Apprise => new AppriseDefinition,
        };
    }

    /**
     * @return list<ChannelDefinition>
     */
    public static function all(): array
    {
        return array_map(self::for(...), ChannelType::cases());
    }
}
