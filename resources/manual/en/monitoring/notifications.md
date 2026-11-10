# Notification channels

A notification channel decides where your monitors' alerts go: to e-mail, to chat, as a push notification or to a webhook. This chapter shows you how to create a channel, how to test it, and where to follow the alerts that were sent.

## How does an alert reach you?

An alert goes only to the channels the monitor is subscribed to. A monitor can subscribe to several channels, and several monitors can use the same channel. You set up subscriptions in the monitor editor, in the **Notification channels** field, or on the channel's page, on the **Monitors** card.

You need at least the **Editor** role to create and change channels.

## Creating a channel

:::steps

1. Open the [[route:ws.channels|Notification channels]] screen and click the [[icon:mdi-plus]] **New channel** button.
2. Choose the type in the **Channel type** field. You cannot change the type later.
3. Fill in the **Name** field.
4. Fill in the settings for the type.
5. In the **Test** section, click the [[icon:mdi-send]] **Send test message** button, and check that the message arrived.
6. Click **Create**.

:::

## Channel types

| Type | Required details |
| --- | --- |
| E-mail | **Recipients** (one or more e-mail addresses) |
| Webhook | **URL**, **HTTP method**, **Body format** |
| Slack, Discord, Microsoft Teams, Mattermost, Google Chat | **Webhook URL** |
| Telegram | **Bot token**, **Chat ID** |
| ntfy | **Server URL**, **Topic** |
| Gotify | **Server URL**, **App token** |
| Pushover | **User key**, **App token** |
| Matrix | **Homeserver URL**, **Room ID**, **Access token** |
| PagerDuty | **Routing key** |
| Opsgenie | **API key**, **Region** |
| Apprise | **Server URL**, plus **Config key** or **Notification URLs** |

By default, the e-mail channel sends through the app's mailer. If you turn on the **Use my own SMTP server** switch, you can enter your own server and sender address.

For a webhook channel, put credentials in the **Secret headers**. If you set a **Signing secret**, the receiving side can verify that the request really came from IntraWatch: the app then also sends an `X-IntraWatch-Signature` header.

:::note

The app never shows tokens, passwords and webhook addresses again after you save them. When you edit, use the **Replace** button to enter a new value, or keep the stored one.

:::

## Delivery settings

- **Active**: an inactive channel gets no alerts. This way you can mute a channel for a while without deleting it.
- **Default channel**: new monitors subscribe to this channel automatically.
- **Apply to existing monitors**: appears only when **Default channel** is on. When you save, it subscribes your existing monitors as well.

## Message text

In the **Message text** section you can set your own **Title template** and **Body template**. If you leave them empty, the built-in text is used. In the template you can use variables such as the alert kind, the monitor's name and address, the message and the duration; the full list is shown below the field.

## Testing a channel

The **Send test message** button sends a test message to the channel right away and shows **Test delivered** or **Test failed**. For a new channel, it tests the settings you have not saved yet. For an existing channel, it uses the saved settings, so save first after you make changes.

## Subscribing monitors to a channel

On an existing channel's page, the **Monitors** card shows how many monitors are subscribed. Select monitors in the **Monitors to change** field (up to 500 at a time), then click **Subscribe** or **Unsubscribe**.

## The channel list

For each channel the list shows whether it is **Active** or **Inactive**, whether it is a default channel, how many monitors use it, and its **Latest delivery**. If the last delivery failed, **Last failed** appears with the error message. At the end of the row, use the [[icon:mdi-pencil]] icon to edit the channel and the [[icon:mdi-trash-can-outline]] icon to delete it. The earlier alert history is kept after you delete it.

## Alert history

On the [[route:ws.alerts|Alert history]] screen you can look up every alert that was sent by **Kind**, **Monitor** and start time (**Since**). Each row shows which channel the alert went to and whether delivery succeeded (**Queued**, **Sent** or **Failed**).

The alert kinds:

| Kind | When it is created |
| --- | --- |
| Down | The monitor went down. |
| Up | The monitor that was down works again. |
| Degraded | The monitor became **Degraded**, and **Alert when degraded** is on. |
| Still down | A reminder, if **Reminder interval (minutes)** is set for the monitor. |
| Flapping | The monitor changed state many times in a short period; while it flaps, you do not get a separate message for every change. |
| Stable | The flapping has ended. |
| Expiring | A certificate or domain is about to expire. |
| Renewed | The expiry date of a certificate or domain moved later. |
| Changed | The certificate or the DNS answer changed. |

**Expiring**, **Renewed** and **Changed** alerts are created only when the monitor's **Notify about certificate, DNS and expiry changes** switch is on. If an alert shows **Suppressed by a parent monitor.**, the monitor's group was already down, so the member's alert was not sent separately. Details: [[manual:monitoring/monitors|Monitors]].
