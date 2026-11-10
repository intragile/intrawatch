# Status pages

A status page is a public web page where your customers and colleagues can see the state of selected monitors, open incidents and planned maintenance without signing in. This chapter shows you how to create, lay out and publish a status page.

## Published and draft pages

Every status page is either **Published** or **Draft**. Visitors cannot reach a page that is not published: its public address behaves as if the page did not exist. This way you can put the page together and review it before anyone can see it.

When you are ready, turn on the **Published** switch and save the page. A published page is available under the `/status/` path, at the address you set in the **Slug** field.

Everyone in the workspace can view status pages. You need at least the **Editor** role to create and change them.

## Creating a status page

:::steps

1. Open the [[route:ws.status-pages|Status pages]] screen and click the [[icon:mdi-plus]] **New status page** button.
2. Fill in the **Title** and **Slug** fields. The slug becomes the public address, for example `/status/our-services`.
3. If needed, write a **Description**, and use the **Language** field to choose whether the page is in English or Hungarian.
4. Leave the **Published** switch off until you have laid out the page.
5. Click **Create**.

:::

After you create the page, the **Layout** card appears, where you can add monitors.

## Building the layout

On the public page, monitors are grouped into sections, such as "Websites" or "Internal systems".

:::steps

1. On the **Layout** card, click the [[icon:mdi-plus]] **Add section** button.
2. Fill in the **Section name** field and, if needed, the **Section description** field.
3. In the **Add a monitor (search by name)** field, find and select the monitors for the section.
4. Set the order with the [[icon:mdi-arrow-up]] and [[icon:mdi-arrow-down]] icons, or by dragging the [[icon:mdi-drag-vertical]] handle.
5. Click **Save layout**.

:::

Each monitor has two switches. The **Uptime** switch also shows the uptime percentage next to the monitor. For a group monitor, the **Group members** switch also shows the members of the group. To take a monitor out of a section, use the [[icon:mdi-close]] icon.

:::warning

You must save the layout separately with the **Save layout** button. The **Save** button saves only the settings. Saving the layout overwrites the whole layout.

:::

## What do visitors see?

At the top of the public page, a summary bar shows the state of the whole page, for example "All systems operational" or "Partial outage". Below it, the monitors in each section appear with their own state and a status bar broken down by day.

Monitor states appear on the page like this:

| Monitor state in the app | On the page |
| --- | --- |
| Up | Operational |
| Degraded | Degraded performance |
| Down | Major outage (Partial outage for a group if some members are still up) |
| Maintenance | Maintenance |
| Pending, Paused | No data |

The page also shows the selected open incidents, closed incidents and upcoming maintenance. An incident or maintenance appears if you selected this status page for it, or if it affects a monitor that is visible on the page (for maintenance, this includes the monitor's group). See [[manual:monitoring/incidents|Incidents]] and [[manual:monitoring/maintenances|Maintenance]].

## Display settings

In the **What visitors see** section you control how much history is shown:

- **Status bar range (days)**: how many days the monitors' status bar shows (between 7 and 365).
- **Uptime shown for**: the time window for the uptime percentage, 1, 7, 30, 90 or 365 days.
- **Incident history (days)**: how far back closed incidents are shown (0–90).
- **Upcoming maintenance (days)**: how far ahead planned maintenance is shown (0–90).
- **Show response times**: when on, response times are shown too.

## Branding

In the **Branding** section you can match the page to your own brand:

- **Accent colour**: a hex value, for example #2563eb.
- **Logo URL** and **Favicon URL**: an https address without credentials.
- **Footer text**: the text shown at the bottom of the page.
- **Custom CSS**: your own style rules for further customization.

## Custom domain

If your installation allows it, you can give the page its own address in the **Custom domain** field, for example `status.example.com`. For this, a DNS record must point to this server. Only the **Admin** or **Owner** role can set a custom domain. If the field does not appear, this feature is not enabled on your installation.

## The status page list

The [[route:ws.status-pages|Status pages]] list shows each page's title and state (**Published** or **Draft**). At the end of the row, use the [[icon:mdi-open-in-new]] icon to open the public page, the [[icon:mdi-pencil]] icon to edit it, and the [[icon:mdi-trash-can-outline]] icon to delete it. When you delete a page, its layout is deleted too, and its public address stops working.

:::note

Changes can take up to 60 seconds to show on public status pages.

:::
