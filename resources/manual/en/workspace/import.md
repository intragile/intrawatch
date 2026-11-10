# Import from Uptime Kuma

If you used Uptime Kuma until now, you can move your monitors, notification channels, tags, status pages, incidents and maintenance windows to an IntraWatch workspace by uploading a single file. The import has two steps: first an analysis, then the actual import.

## Who can import?

You can import with the **Admin** or **Owner** role. With these roles, the [[icon:mdi-database-import-outline]] **Import from Uptime Kuma** item appears in the menu, in the **Manage workspace** group.

Imported items go into the workspace where you start the import. If you bring over several Uptime Kuma instances, import each one into a separate workspace.

## Supported files

| Source | Uptime Kuma version | What it contains |
| --- | --- | --- |
| SQLite database (`.db`, `.sqlite`, `.sqlite3`), for example a copy of `kuma.db` | 2.x and 1.23 | all supported item types |
| JSON backup (a file made with the Uptime Kuma 1.23 backup feature) | 1.23 | only monitors, notification channels and tags |

The upload form shows the largest file size you can upload. You cannot upload an SQL dump. Uptime Kuma 2.x does not make JSON backups, so import the database file instead.

:::tip

Make a consistent copy of the database. To do this, stop Uptime Kuma before you copy it, or while it runs, run `sqlite3 kuma.db ".backup kuma-export.db"` and upload the resulting file.

:::

## Upload and analysis

:::steps

1. Open the [[route:ws.import|Import from Uptime Kuma]] page.
2. In the **Upload a file** section, choose the file in the **Kuma database or backup** field.
3. Decide whether you want **Include uptime history**. It is on by default.
4. If you do not want the imported monitors to start yet, turn on **Import monitors paused**.
5. Click **Upload and analyse**.

:::

The analysis runs in the background, and the page refreshes by itself. Nothing is created in the workspace during the analysis.

A workspace can have only one open import at a time. If you have not finished an earlier import, finish or delete it first; only then can you upload a new file.

## The analysis report

When the analysis is done, the **Analysis report** appears:

- the **Counts by item and outcome** table sums up, per item type, what would happen during the import;
- the **Not migrated** section lists what does not come over and what you need to set up again;
- the **History** section shows how many monitors' uptime history comes over;
- the **Notes** section contains further information;
- the **Items** table shows the outcome row by row, with the reason in the **Details** column.

The **Items** table opens with the **Needs attention** filter, so it shows the rows that will not be created. Use the **Item type** and **Outcome** pickers to narrow down the list.

The possible outcomes:

| Outcome | Meaning |
| --- | --- |
| Would create | The import would create it (only in the analysis). |
| Created | The import created it. |
| Already present | An earlier import already brought it over; nothing changes. |
| Skipped | IntraWatch has no equivalent. |
| Invalid | It failed validation; the details show the error. |
| Failed | An unexpected error occurred. |

## Running the import

If the report looks right, click **Run import**, then click **Run import** again in the confirmation window. The window sums up what will be created. The import does not touch items that already exist.

The import also runs in the background. When it is done, **The import is complete.** message and the **Import report** appear. The link in the message, **Open the monitors list**, takes you to the [[route:ws.monitors|Monitors]] page.

You can safely run the import again from the same source. Items brought over earlier get the **Already present** outcome and do not change. This way, the changes and deletions you made in the workspace in the meantime are kept.

:::warning

If you import the same Uptime Kuma instance once from a JSON backup and once from a database file, IntraWatch cannot match the items, and you get duplicates.

:::

## What the import does not bring over

The import does not bring over users, API keys, Docker hosts or remote browsers; the report counts these in the **Not migrated** section. It also skips monitor types and notification providers that IntraWatch does not support, as well as manual maintenance without a schedule. The report flags these with a note.

After the import, check your notification channels and send a test message. Details: [[manual:monitoring/notifications|Notification channels]].

## Previous imports

The **Previous imports** table lists the workspace's imports with the **Status**, **Date** and **File** columns.

- Use the [[icon:mdi-file-document-outline]] **View report** icon to view the report again.
- Use the [[icon:mdi-delete-outline]] **Delete import** icon to delete the import and its report. Items that were already created are kept. You cannot delete an import that is queued or in progress.

IntraWatch deletes the uploaded file after the import finishes or fails, or after 24 hours at the latest. If you do not run an analyzed import within 24 hours, you need to upload the file again.
