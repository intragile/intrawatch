# Maintenance

During a maintenance window the selected monitors do not alert, and the status page tells visitors about the planned work in advance. This chapter shows you how to schedule one-off or recurring maintenance, and how to end it early.

## What does a maintenance window do?

While a window is running, the affected monitors are in the **Maintenance** state, whether their checks succeed or fail. In this state a monitor sends no alerts, so a planned restart or upgrade does not wake up your colleagues for nothing.

When the window ends, the monitors go back to normal. If a service is still down at that point, the monitor alerts, unless it already sent an alert about this outage before the maintenance.

If you select a group monitor, the maintenance also applies to all members of the group.

Everyone in the workspace can view maintenance. You need at least the **Editor** role to schedule and change it.

## Scheduling maintenance

:::steps

1. Open the [[route:ws.maintenances|Maintenance]] screen and click the [[icon:mdi-plus]] **Schedule maintenance** button.
2. Fill in the **Title** field and, if needed, the **Description** field.
3. Choose the **Schedule type** and the **Time zone**, then fill in the times (see below).
4. Under **Affected monitors and status pages**, select at least one monitor in the **Affected monitors** field.
5. If you also want to show the maintenance on a status page that none of the affected monitors are on, select that page too.
6. Click **Create**.

:::

The **Active** switch is on by default. Inactive maintenance never starts, so you can suspend a schedule without deleting it.

## Schedule types

| Schedule type | When to use it | Fields to fill in |
| --- | --- | --- |
| One-off | For a single piece of work | **Starts**, **Ends** |
| Every N days | For work that repeats every few days | **Start time**, **Duration (minutes)**, **Repeat every (days)** |
| Weekly | For work that repeats on certain days of the week | **Start time**, **Duration (minutes)**, **Weekdays** |
| Monthly | For work that repeats on certain days of the month | **Start time**, **Duration (minutes)**, **Days of the month** |
| Cron expression | For more complex repetition | **Cron expression**, **Duration (minutes)** |

A few things to know about the times:

- All times are in the selected **Time zone**.
- For one-off maintenance you can leave the **Ends** field empty if you do not know yet how long the work takes. You then close it with the **End now** button.
- For a monthly window, days 29–31 are skipped in shorter months. If you need the last day of the month, choose **Last day**.
- A **Cron expression** has five fields: minute, hour, day, month, weekday. For example, `0 2 * * 0` starts a window every Sunday at 2 a.m.
- For a recurring schedule, use the **Valid from** and **Valid until** fields to limit the days on which the schedule applies. Both fields are optional.

## Window preview

On a saved maintenance's page, the **Window** section shows whether the maintenance is **In progress now** and when the **Next window** is. If there are no more windows, you see **No upcoming window.** The server calculates the preview from the saved schedule, so save first after you make changes.

## The maintenance list

On the [[route:ws.maintenances|Maintenance]] screen, use the filter at the top to switch between **All**, **In progress**, **Upcoming** and **Ended** maintenance. For each maintenance the list shows the schedule type, its state, the next or current window, and the affected monitors.

What the states mean:

| State | Meaning |
| --- | --- |
| In progress | The window is running, and the monitors do not alert. |
| Upcoming | There are windows still to come. |
| Ended | There are no more windows. |
| Inactive | The **Active** switch is off. |

At the end of the row, use the [[icon:mdi-pencil]] icon to edit the maintenance and the [[icon:mdi-trash-can-outline]] icon to delete it.

## Ending maintenance early

If the work finished sooner, you can close a one-off maintenance that is in progress:

:::steps

1. In the list or on the maintenance's page, click the [[icon:mdi-stop-circle-outline]] **End now** button.
2. Confirm with the **End now** button.

:::

The maintenance ends immediately, and the monitors alert again.

:::warning

You cannot end recurring maintenance. To stop a recurring window, turn off the **Active** switch. If you delete maintenance that is currently running, the monitors also alert again immediately.

:::

## How it appears on the status page

A status page shows the maintenance if you selected the page for it, or if one of the affected monitors (or its group) is on the page. While maintenance is in progress, the affected monitors show as "Maintenance" on the page, and upcoming windows appear in advance. The status page's **Upcoming maintenance (days)** setting decides how far ahead visitors can see planned work. Details: [[manual:monitoring/status-pages|Status pages]].

:::note

Changes can take up to 60 seconds to show on public status pages.

:::
