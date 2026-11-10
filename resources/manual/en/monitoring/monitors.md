# Monitors

A monitor regularly checks a website, server, certificate or scheduled job, and alerts you when something breaks. This chapter shows you how to create a monitor and what its states mean.

## The monitor list

The left side of the [[route:ws.monitors|Monitors]] screen lists all monitors with their state, latency and a bar of recent checks. Click a monitor to open its details next to the list. The state counters above the list also work as filters, and under **Filters** you can filter by type, tag, activity and name.

![The monitor list with eight monitors. The details of the selected, degraded Billing monitor appear beside the list: the bar of the last 50 checks, availability and the latency chart](/admin/manual/en/monitor-list.webp)

You need at least the **Editor** role to create and change monitors. With the **Viewer** role you can see everything but change nothing.

## What do the states mean?

| State | Meaning |
| --- | --- |
| Up | The last check succeeded. |
| Degraded | The check succeeded but exceeded the slowness threshold you set, or a certificate or domain expires in fewer days than the value in the **Degraded below (days left)** field. |
| Pending | The new or restarted monitor has not checked yet, or a check failed but retries are not used up yet. |
| Down | The check still fails after all retries. |
| Maintenance | The monitor is in a maintenance window, so it does not alert. |
| Paused | The monitor is turned off. The app does not check it, and it does not alert. |

The **Pending** state is an intentional step in between: a single failed check does not mean an outage yet. A down alert is sent only when the monitor enters the **Down** state.

## Creating a monitor

:::steps

1. Above the monitor list, click the [[icon:mdi-plus]] **New monitor** button, or open the [[route:ws.monitor.new|New monitor]] screen directly.
2. Choose the type in the **Monitor type** field. You cannot change the type later.
3. Fill in the **Name** field and, if needed, the **Description** and **Parent group** fields.
4. Fill in the settings for the type (see below).
5. Set up the **Timing**, **Alerting** and **Notifications and tags** sections.
6. Click **Create**.

:::

![The top of the New monitor form: the HTTP monitor type, "Webshop" in the Name field, then the Description and Parent group fields, the Active and Inverted result switches, and the Timing section](/admin/manual/en/monitor-new.webp)

In the **Notification channels** field, the channels marked as default are already selected. The [[manual:monitoring/notifications|Notification channels]] chapter explains how to create channels. In the **Tags** field you can choose from the workspace's existing tags. For a new tag, type its name and choose the **Create tag “…”** row or press Enter: the tag is created and assigned to the monitor. Each tag can also carry an optional value, such as `prod` or `eu-west`. On the [[route:ws.tags|Tags]] screen under **Manage workspace** you can rename, recolor and delete existing tags; a deleted tag is removed from every monitor, but the monitors keep working.

## Monitor types

| Type | What it checks | Key fields |
| --- | --- | --- |
| HTTP | The response of a website or API | **URL**, **Method**, **Accepted status codes**, **Authentication**, **Response checks**, **Certificates**, **Proxy** |
| TCP | Whether a port is open | **Host**, **Port** |
| Ping | Whether a machine answers ping | **Host**, **Packets per check** |
| DNS | How a name resolves | **Name to resolve**, **Record type**, **Resolver**, **Expected values**, **Matching** |
| TLS certificate | A server's certificate | **Host**, **Port**, **Upgrade protocol**, **Warn before expiry (days)** |
| Domain expiry | When a domain registration expires | **Domain name**, **Warn before expiry (days)** |
| Push | Whether a service checks in regularly | **Grace period** |
| Cron | Whether a scheduled job runs on time | **Cron schedule**, **Schedule time zone**, **Grace period**, **Maximum runtime** |
| Group | The combined state of several monitors | **Group status rule** |

For an HTTP monitor, enter passwords and tokens in the **Authentication** or **Secret headers** field, not among the plain headers. The app never shows secret values again after you save them.

### Push and Cron monitors

These types do not query anything themselves: your job must check in at a unique address. After you save, the **Push URL** appears on the monitor's detail page, on the **Push endpoint** card, where you can copy it with the [[icon:mdi-content-copy]] icon. Treat the URL like a password. If it leaks, use the [[icon:mdi-key-change]] **Rotate token** button to get a new address; the old one stops working immediately.

### Groups

A group's state comes from its members. To put a monitor in a group, choose the group in the monitor's **Parent group** field. The rule can be **All members up**, **Any member up** or **At least N members up**. If a group is down and has an active notification channel, the app suppresses its members' alerts, so you do not get many messages at once.

## Check interval, timeout and retries

The full **Timing** section appears for types that are polled regularly. A push monitor shows only the **Expected check-in interval** and the **Retry interval**: the job must check in that often, and the monitor is marked down when it has not checked in within that interval plus the grace period. Each further missed check-in is counted after the retry interval.

- **Check interval**: how often the app checks the target, in seconds.
- **Timeout**: how long the app waits for a check's response. It can be at most 80% of the check interval.
- **Retries**: how many times the app repeats a failed check before it marks the monitor down (0–10). Meanwhile the monitor is **Pending**.
- **Retry interval**: how soon the app repeats the check after a failure, at least 20 seconds.
- **Degraded above (latency)**: above this value the monitor becomes **Degraded**. Leave it empty to turn it off.

The **Inverted result** switch flips the result: a failed check then counts as up. This is useful, for example, when you want to make sure a service is not reachable from outside.

## Alerting settings

- **Reminder interval (minutes)**: while the monitor is down, the alert repeats every this many minutes. 0 sends no reminders.
- **Alert when degraded**: when on, the **Degraded** state also alerts, after the **Degraded alert delay**.
- **Notify about certificate, DNS and expiry changes**: this switch turns on alerts about certificate and DNS changes and about upcoming expiries. It is on by default for new monitors; leave it on for TLS, domain and DNS monitors.

If a monitor changes state many times in a short period, the app does not send a message for every change. Instead it sends one **Flapping** alert, then a **Stable** alert when the monitor has settled.

## Actions on the detail page

The detail page shows the **Latency** chart, the **Availability**, and the **Results**, **Events** and **Alerts** tabs. As an editor you also have these buttons:

- [[icon:mdi-refresh]] **Check now**: requests an extra check right away.
- [[icon:mdi-pause]] **Pause** and [[icon:mdi-play]] **Resume**: turn the monitor off and back on.
- [[icon:mdi-pencil]] **Edit**: opens the settings.

:::tip

You can change several monitors at once. Click the [[icon:mdi-checkbox-multiple-marked-outline]] **Select** button in the list, select the monitors, then choose from **Bulk actions**: pause, resume, delete, or add and remove a tag or notification channel.

:::
