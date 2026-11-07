<?php

namespace App\Alerting\Channels;

use App\Alerting\Channels\Senders\DiscordSender;
use App\Alerting\Channels\Senders\EmailSender;
use App\Alerting\Channels\Senders\GoogleChatSender;
use App\Alerting\Channels\Senders\MattermostSender;
use App\Alerting\Channels\Senders\SlackSender;
use App\Alerting\Channels\Senders\TeamsSender;
use App\Alerting\Channels\Senders\WebhookSender;
use App\Enums\ChannelType;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

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
            default => throw new InvalidArgumentException("No sender for channel type {$type->value}."),
        };

        return $this->container->make($class);
    }
}
