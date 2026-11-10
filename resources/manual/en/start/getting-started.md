# Getting started

This chapter walks you through your first steps after you sign in: you create a monitor, set up a notification channel, publish a status page, and create an API token.

## Finding your way around

The **Workspace** switcher sits at the top of the left-hand menu. Everything you create below belongs to the workspace that is currently selected. The most important menu items are:

- [[icon:mdi-monitor-dashboard]] **Monitors**
- [[icon:mdi-bell-outline]] **Notification channels**
- [[icon:mdi-web-check]] **Status pages**
- in the **Manage workspace** group, among others, [[icon:mdi-account-group]] **Members** and [[icon:mdi-key-variant]] **API tokens**
- in the **Help and news** group, [[icon:mdi-help-circle-outline]] **Help**, this manual, and [[icon:mdi-history]] **Changelog**, what changed in each release

![The IntraWatch screen after signing in: the menu with the workspace selector on the left, the monitor list with statuses and check bars in the middle, and the overview with the down and the degraded monitor on the right](/admin/manual/en/overview.webp)

The [[icon:mdi-help-circle-outline]] icon next to a page title opens the help chapter that describes that page.

Click your name at the bottom of the menu to open the **Account menu**. There you find the **Account and security** page, the theme and language switches, and **Sign out**.

The menu shows only the items your role gives you access to. You need at least the **Editor** role to create monitors, channels and status pages. If you do not see a button, ask one of the workspace admins for a higher role. Roles are covered in the [[manual:workspace/workspace|Workspace and members]] chapter.

## 1. Your first monitor

A monitor checks a service, such as a website, at regular intervals.

:::steps

1. Open the [[route:ws.monitors|Monitors]] page.
2. Click the [[icon:mdi-plus]] **New monitor** button.
3. In the **Monitor type** field, keep the **HTTP** type or choose another one. You cannot change the type after the monitor is created.
4. Enter a **Name**, and type the address to check in the **URL** field.
5. Change the **Check interval** if you need to.
6. Click **Create**.

:::

The check results appear on the monitor's detail page. The [[manual:monitoring/monitors|Monitors]] chapter describes the other types and settings.

## 2. Notification channel

A notification channel tells IntraWatch where to send an alert when a monitor goes down. A channel can be, for example, email, Slack, Microsoft Teams, Telegram or a webhook.

:::steps

1. Open the [[route:ws.channels|Notification channels]] page.
2. Click the [[icon:mdi-plus]] **New channel** button.
3. Choose the **Channel type**, enter a **Name**, and fill in the fields for that type.
4. Turn on **Default channel** if you want every new monitor to alert this channel as well.
5. For existing monitors, including the one you just created, turn on **Apply to existing monitors**.
6. In the **Test** section, use the **Send test message** button to check your settings.
7. Click **Create**.

:::

You can also set a monitor's channels in the monitor editor, in the **Notifications and tags** section. Details: [[manual:monitoring/notifications|Notification channels]].

## 3. Status page

A status page is a public page where visitors can see the state of selected monitors without signing in.

:::steps

1. Open the [[route:ws.status-pages|Status pages]] page.
2. Click the [[icon:mdi-plus]] **New status page** button.
3. Enter the **Title** and the **Slug**.
4. Click **Create**.
5. In the **Layout** section, click **Add section**, then add the monitor in the **Add a monitor (search by name)** field.
6. Click **Save layout**.
7. Turn on the **Published** switch and click **Save**. A page that is not published is not publicly available.

:::

Use the [[icon:mdi-open-in-new]] **Open public page** button to see the result. Changes can take up to 60 seconds to show on the public page. Details: [[manual:monitoring/status-pages|Status pages]].

## 4. API token

If you want to reach IntraWatch from a script or another system, create an API token.

:::steps

1. Open the [[route:ws.tokens|API tokens]] page.
2. Enter a name for the token in the **Name** field, and tick the **Abilities** you need, for example **Read monitors**.
3. Click **Create token**.
4. Copy the token that appears with the [[icon:mdi-content-copy]] icon, then click **I have copied it**.

:::

:::warning

The token is shown only once. You cannot retrieve it later. Store it somewhere safe.

:::

The [[manual:workspace/api-tokens|API tokens]] chapter explains how to use the token and what the abilities do.

## What next?

- Protect your account with two-factor authentication: [[manual:account/account|Your account]].
- Invite your colleagues: [[manual:workspace/workspace|Workspace and members]].
- If you used Uptime Kuma until now, bring your settings over: [[manual:workspace/import|Import from Uptime Kuma]].
- You can record planned downtime as maintenance: [[manual:monitoring/maintenances|Maintenance]]. You can tell visitors about outages with an incident: [[manual:monitoring/incidents|Incidents]].
