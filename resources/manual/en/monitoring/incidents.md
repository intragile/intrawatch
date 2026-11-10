# Incidents

With an incident you can tell visitors about a problem or an outage: you describe what happened and follow the fix with updates. This chapter shows you how to create, update and close an incident, and how it appears on status pages.

## How is an incident different from an alert?

An alert is sent automatically by the monitor, to your team. You create an incident by hand, and it is mainly for the visitors of your status page. IntraWatch does not open an incident on its own when a monitor goes down. You decide when to say something and what to say.

Everyone in the workspace can view incidents. You need at least the **Editor** role to create and change them.

## Incident status and impact

An incident has a **Status** and an **Impact** field.

| Status | When to use it |
| --- | --- |
| Investigating | You know about the problem and are looking for the cause. |
| Identified | You found the cause and are working on a fix. |
| Monitoring | You deployed the fix and are watching whether it holds. |
| Resolved | The problem is gone. This status closes the incident. |

**Impact** shows how serious the problem is: **No impact**, **Minor**, **Major** or **Critical**. The impact of an open incident also shows on the status page:

| Impact | State of the affected items on the status page |
| --- | --- |
| No impact | Does not change the state. |
| Minor | At least "Degraded performance" |
| Major | At least "Partial outage" |
| Critical | "Major outage" |

The status page always shows the more serious state: if the monitor itself is in a worse state than the incident's impact suggests, the monitor's state is shown.

## Creating an incident

:::steps

1. Open the [[route:ws.incidents|Incidents]] screen and click the [[icon:mdi-plus]] **New incident** button.
2. Fill in the **Title** field and choose the **Impact**.
3. Under **First update**, choose the **Status** and write the **Message**. This becomes the first entry on the timeline.
4. Under **Affected**, choose the affected monitors in **Affected monitors**. In the **Shown on status pages** field, choose any other status pages where the incident should show even if the affected monitors are not on them.
5. Click **Create incident**.

:::

:::warning

A status page shows the incident if you selected that page in the **Shown on status pages** field, or if **Affected monitors** includes a monitor that is visible on the page. If you want to keep an incident inside the workspace only, do not select any monitor that is also on a public status page.

:::

## Posting an update

While you work on the fix, you can keep visitors informed with updates. Open the incident from the list and use the **Post an update** card:

:::steps

1. Choose the new **Status**.
2. Write the **Message**, for example what you found or when you expect the fix.
3. Click **Post update**.

:::

The incident always takes the status of the latest update. So to close an incident, post an update with the **Resolved** status.

:::note

Changes can take up to 60 seconds to show on public status pages.

:::

## Correcting the timeline

The **Timeline** card shows every update with its time. Next to an entry:

- use the [[icon:mdi-pencil]] icon to correct the message text. The status and time do not change, and the entry gets an "edited" mark;
- use the [[icon:mdi-trash-can-outline]] icon to delete the entry. The incident's status is then recalculated from the latest remaining update.

## Changing the details

On the **Details** card you can change the **Title** and **Impact** fields, as well as the affected monitors and status pages, at any time. Save your changes with the **Save details** button.

## The incident list

On the [[route:ws.incidents|Incidents]] screen, use the filter at the top to switch between **All**, **Open** and **Resolved** incidents. For each incident the list shows the impact, the status, and when it started and was resolved.

## Deleting an incident

On the incident's page, use the [[icon:mdi-trash-can-outline]] **Delete** button to delete the whole incident. The incident and its timeline then also disappear from status pages.

:::tip

It is usually better not to delete a closed incident: the status page lists it under **Past incidents**, which shows visitors that you are transparent. The [[manual:monitoring/status-pages|Status pages]] chapter explains how far back these are shown.

:::

If you want to tell visitors about planned work in advance, schedule maintenance instead of an incident. The [[manual:monitoring/maintenances|Maintenance]] chapter covers this.
