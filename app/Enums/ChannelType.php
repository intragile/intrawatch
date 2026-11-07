<?php

namespace App\Enums;

use App\Alerting\Channels\ChannelDefinition;
use App\Alerting\Channels\ChannelRegistry;

enum ChannelType: string
{
    case Email = 'email';
    case Webhook = 'webhook';
    case Slack = 'slack';
    case Discord = 'discord';
    case Teams = 'teams';
    case Mattermost = 'mattermost';
    case GoogleChat = 'google_chat';
    case Telegram = 'telegram';
    case Ntfy = 'ntfy';
    case Gotify = 'gotify';
    case Pushover = 'pushover';
    case Matrix = 'matrix';
    case PagerDuty = 'pagerduty';
    case Opsgenie = 'opsgenie';
    case Apprise = 'apprise';

    public function definition(): ChannelDefinition
    {
        return ChannelRegistry::for($this);
    }
}
