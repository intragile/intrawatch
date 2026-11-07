<?php

namespace App\Alerting\Channels;

use App\Alerting\Channels\Senders\AppriseSender;
use App\Alerting\Channels\Senders\DiscordSender;
use App\Alerting\Channels\Senders\EmailSender;
use App\Alerting\Channels\Senders\GoogleChatSender;
use App\Alerting\Channels\Senders\GotifySender;
use App\Alerting\Channels\Senders\MatrixSender;
use App\Alerting\Channels\Senders\MattermostSender;
use App\Alerting\Channels\Senders\NtfySender;
use App\Alerting\Channels\Senders\OpsgenieSender;
use App\Alerting\Channels\Senders\PagerDutySender;
use App\Alerting\Channels\Senders\PushoverSender;
use App\Alerting\Channels\Senders\SlackSender;
use App\Alerting\Channels\Senders\TeamsSender;
use App\Alerting\Channels\Senders\TelegramSender;
use App\Alerting\Channels\Senders\WebhookSender;
use App\Enums\ChannelType;
use Illuminate\Contracts\Container\Container;

final class SenderRegistry
{
    public function __construct(private readonly Container $container) {}

    public function for(ChannelType $type): ChannelSender
    {
        $class = match ($type) {
            ChannelType::Email => EmailSender::class,
            ChannelType::Webhook => WebhookSender::class,
            ChannelType::Slack => SlackSender::class,
            ChannelType::Discord => DiscordSender::class,
            ChannelType::Teams => TeamsSender::class,
            ChannelType::Mattermost => MattermostSender::class,
            ChannelType::GoogleChat => GoogleChatSender::class,
            ChannelType::Telegram => TelegramSender::class,
            ChannelType::Ntfy => NtfySender::class,
            ChannelType::Gotify => GotifySender::class,
            ChannelType::Pushover => PushoverSender::class,
            ChannelType::Matrix => MatrixSender::class,
            ChannelType::PagerDuty => PagerDutySender::class,
            ChannelType::Opsgenie => OpsgenieSender::class,
            ChannelType::Apprise => AppriseSender::class,
        };

        return $this->container->make($class);
    }
}
